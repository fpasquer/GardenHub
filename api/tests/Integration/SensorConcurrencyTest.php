<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AuthenticatedApi;
use App\Tests\Support\Command;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\LockWaitProof;
use App\Tests\Support\SensorConcurrencyKernel;
use App\Tests\Support\TemporaryWorkspace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Tests\SensorIdentity\SensorLockObserverConfig;
use Tests\SensorIdentity\SensorLockObserverMiddleware;
use Tests\SensorIdentity\SensorPostLoadRelabelConfig;
use Tests\SensorIdentity\SensorPostLoadRelabeler;

#[Group('functional_gardenhub-sensor-concurrency')]
final class SensorConcurrencyTest extends TestCase
{
    private SensorConcurrencyKernel $kernel;
    private TemporaryWorkspace $workspace;
    private AuthenticatedApi $api;
    private Connection $connection;
    private array $device;
    private array $sensor;
    private ?Process $writer = null;

    protected function setUp(): void
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SensorConcurrencyKernel($this->workspace->path);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($manager, 'sensor_identity');
        GuardedSchema::resetMeasurements($manager, 'sensor_identity');
        $this->connection = $manager->getConnection();
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
        $this->connection->fetchOne('SELECT COUNT(*) FROM performance_schema.threads');
        $this->connection->fetchOne('SELECT COUNT(*) FROM performance_schema.data_lock_waits');
        $this->api = new AuthenticatedApi($this->kernel, $manager);
        $this->device = $this->success('POST', '/api/devices',
            ['name' => 'Concurrency device', 'devEui' => 'conc-dev'], 201);
        $this->sensor = $this->success('POST', '/api/sensors',
            ['device' => $this->device['@id'], 'type' => 'temp_a',
                'unit' => "\u{00B0}C", 'label' => 'S1'], 201);
    }

    protected function tearDown(): void
    {
        SensorLockObserverMiddleware::disarm();
        SensorPostLoadRelabeler::disarm();
        $this->writer?->stop(0);
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function testMeasurementFirstBlocksThenRejectsIdentityUpdate(): void
    {
        $writer = new \PDO(...$this->pdoParts());
        $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $writer->beginTransaction();
        try {
            $writer->prepare('SELECT id FROM sensor WHERE id = ? FOR UPDATE')
                ->execute([$this->sensor['id']]);
            $id = (string) Uuid::v4();
            $writer->prepare('INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (?, 1.0, NOW(), NOW(), ?, ?)')
                ->execute([$this->sensor['id'], $id, 'temp_a']);
            $capture = $this->kernel->getContainer()->get('test.exception_capture_listener');
            $capture->reset();
            [$response] = $this->api->request('PATCH', $this->sensor['@id'], ['type' => 'temp_b']);
            self::assertGreaterThanOrEqual(500, $response->getStatusCode());
            self::assertTrue(LockWaitProof::isTimeout($capture->captured()),
                $capture->captured()?->getMessage() ?? 'No original exception captured.');
            $writer->commit();
            [$response, $body] = $this->api->request('PATCH', $this->sensor['@id'], ['type' => 'temp_b']);
            $this->assertViolation(422, $response->getStatusCode(), $body, 'type');
            self::assertSame(1, $this->countMeasurements($id));
            $this->assertUnchangedIdentity();
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
    }

    public function testIdentityFirstBlocksRealChildWriterThenRejectsIt(): void
    {
        $id = (string) Uuid::v4();
        $coordination = $this->workspace->path.'/writer.json';
        $parent = (int) $this->connection->fetchOne('SELECT CONNECTION_ID()');
        SensorLockObserverMiddleware::arm(new SensorLockObserverConfig(
            (int) $this->sensor['id'], function () use ($id, $coordination, $parent): void {
                $this->writer = new Process([PHP_BINARY,
                    Command::root().'/tests/sensor-identity/src/measurement-writer.php',
                    $this->api->getKey(), $this->sensor['@id'], $id, $coordination]);
                $this->writer->setTimeout(15);
                $this->writer->start();
                $requesting = LockWaitProof::connectionId($coordination);
                self::assertNotSame($parent, $requesting);
                LockWaitProof::wait($this->connection, $requesting, $parent);
            },
        ));
        $this->success('PATCH', $this->sensor['@id'], ['type' => 'temp_b']);
        SensorLockObserverMiddleware::disarm();
        self::assertInstanceOf(Process::class, $this->writer);
        self::assertSame(0, $this->writer->wait(), substr($this->writer->getErrorOutput(), -65536));
        $output = json_decode($this->writer->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($output['connectionId'], $output['connectionIdAfter']);
        $this->assertViolation(422, $output['status'],
            json_decode($output['body'], true, 512, JSON_THROW_ON_ERROR), 'sensor');
        self::assertSame(0, $this->countMeasurements($id));
    }

    public function testMidRequestIdentityDriftRejectsMeasurement(): void
    {
        SensorPostLoadRelabeler::arm(new SensorPostLoadRelabelConfig(
            (int) $this->sensor['id'], 'temp_b', ...$this->pdoParts(),
        ));
        $id = (string) Uuid::v4();
        [$response, $body] = $this->api->request('POST', '/api/measurements', [
            'sensor' => $this->sensor['@id'], 'value' => 2.0,
            'measuredAt' => '2020-01-01T00:00:00+00:00', 'deduplicationId' => $id,
        ]);
        SensorPostLoadRelabeler::disarm();
        $this->assertViolation(422, $response->getStatusCode(), $body, 'sensor');
        self::assertSame(0, $this->countMeasurements($id));
        self::assertSame('temp_b', $this->connection->fetchOne(
            'SELECT type FROM sensor WHERE id = ?', [$this->sensor['id']],
        ));
    }

    private function assertUnchangedIdentity(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT device_id, type, unit, label FROM sensor WHERE id = ?', [$this->sensor['id']],
        );
        self::assertSame((int) $this->device['id'], (int) $row['device_id']);
        self::assertSame('temp_a', $row['type']);
        self::assertSame("\u{00B0}C", $row['unit']);
        self::assertSame('S1', $row['label']);
    }

    private function pdoParts(): array
    {
        $parts = parse_url((string) getenv('DATABASE_URL'));

        return [sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/')),
            $parts['user'] ?? 'root', $parts['pass'] ?? ''];
    }

    private function countMeasurements(string $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$id],
        );
    }

    private function assertViolation(int $expected, int $status, ?array $body, string $field): void
    {
        self::assertSame($expected, $status);
        self::assertContains($field, array_column($body['violations'] ?? [], 'propertyPath'));
    }

    private function success(string $method, string $uri, array $body, int $status = 200): array
    {
        [$response, $decoded] = $this->api->request($method, $uri, $body);
        self::assertSame($status, $response->getStatusCode(), $response->getContent());

        return $decoded;
    }
}