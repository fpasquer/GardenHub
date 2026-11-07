<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\MqttDeliveryHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\MqttLifecycle\SensorRelabelConfig;
use Tests\MqttLifecycle\SensorRelabelMiddleware;

#[Group('functional_gardenhub-mqtt-identity-race')]
final class MqttIdentityRaceTest extends TestCase
{
    private MqttDeliveryHarness $harness;
    private string $id;
    private int $sensor;

    protected function setUp(): void
    {
        $this->harness = new MqttDeliveryHarness();
        $connection = $this->harness->connection;
        $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('identity-race-device', 'identity-race-device', NOW())");
        $device = (int) $connection->lastInsertId();
        $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$device, 'battery', 'V', 'BatV']);
        $this->sensor = (int) $connection->lastInsertId();
        $this->id = (string) Uuid::v4();
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.6],
            device: 'identity-race-device', id: $this->id, name: 'Identity race sensor'));
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/'));
        SensorRelabelMiddleware::arm(new SensorRelabelConfig($this->sensor, 'mV',
            $dsn, $parts['user'] ?? 'root', $parts['pass'] ?? ''));
        $this->subscriber()->armFailureCapture($this->id);
    }

    protected function tearDown(): void
    {
        SensorRelabelMiddleware::disarm();
        $this->subscriber()->disarmFailureCapture();
        $this->harness->shutdown();
    }

    public function testPersistentIncompatibilityRejectsEveryRetry(): void
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->harness->makeAvailable();
            $this->harness->consume();
        }
        $subscriber = $this->subscriber();
        self::assertSame(4, $subscriber->capturedFailureCount());
        self::assertSame(3, $subscriber->capturedRetryCount());
        $messages = $subscriber->capturedFailureMessages();
        self::assertStringContainsString('identity changed', $messages[0]);
        foreach (array_slice($messages, 1) as $message) {
            self::assertStringContainsString('incompatible', $message);
        }
        self::assertSame(1, $this->queueCount('failed'));
        self::assertSame(0, $this->queueCount('async'));
        self::assertSame(0, (int) $this->harness->connection->fetchOne(
            'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$this->id]));
    }

    public function testRestoredIdentityAllowsRetryToPersist(): void
    {
        $this->harness->makeAvailable();
        $this->harness->consume();
        $this->harness->connection->executeStatement(
            'UPDATE sensor SET unit = ? WHERE id = ?', ['V', $this->sensor]);
        $this->harness->makeAvailable();
        $this->harness->consume();
        $subscriber = $this->subscriber();
        self::assertSame(1, $subscriber->capturedFailureCount());
        self::assertSame(1, $subscriber->capturedRetryCount());
        self::assertStringContainsString('identity changed', $subscriber->capturedFailureMessages()[0]);
        self::assertSame(1, (int) $this->harness->connection->fetchOne(
            'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND value = 3.6 AND type = ?', [$this->id, 'battery']));
        self::assertSame('V', $this->harness->connection->fetchOne(
            'SELECT unit FROM sensor WHERE id = ?', [$this->sensor]));
        self::assertSame(0, $this->queueCount('async'));
        self::assertSame(0, $this->queueCount('failed'));
    }

    private function subscriber(): \Tests\MqttLifecycle\ScenarioSubscriber
    {
        return $this->harness->kernel->getContainer()->get('test.scenario_subscriber');
    }

    private function queueCount(string $queue): int
    {
        return (int) $this->harness->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE ?', [$queue, '%'.$this->id.'%']);
    }
}