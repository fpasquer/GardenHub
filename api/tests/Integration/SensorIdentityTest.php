<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AuthenticatedApi;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\SchedulerKernel;
use App\Tests\Support\TemporaryWorkspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[Group('functional_gardenhub-sensor-identity')]
final class SensorIdentityTest extends TestCase
{
    private TemporaryWorkspace $workspace;
    private SchedulerKernel $kernel;
    private EntityManagerInterface $manager;
    private AuthenticatedApi $api;
    private string $device1;
    private string $device2;
    private string $sensor;
    private string $measurement;
    private ?int $measuredSensorId = null;

    protected function setUp(): void
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SchedulerKernel($this->workspace->path);
        $this->kernel->boot();
        $this->manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($this->manager, 'sensor_identity');
        GuardedSchema::resetMeasurements($this->manager, 'sensor_identity');
        $connection = $this->manager->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        $this->api = new AuthenticatedApi($this->kernel, $this->manager);
        $this->device1 = $this->success('POST', '/api/devices', [
            'name' => 'Identity device 1', 'devEui' => 'sid-dev-1',
            'description' => 'Serialization group regression fixture',
        ], 201)['@id'];
        $this->device2 = $this->success('POST', '/api/devices', [
            'name' => 'Identity device 2', 'devEui' => 'sid-dev-2',
        ], 201)['@id'];
    }

    protected function tearDown(): void
    {
        if (null !== $this->measuredSensorId) {
            self::assertSame(1, (int) $this->manager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM measurement WHERE sensor_id = ?',
                [$this->measuredSensorId],
            ));
        }
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function testNewSensorAcceptsItsIdentity(): void
    {
        $sensor = $this->createSensor();
        self::assertSame('temp_a', $sensor['type']);
        self::assertSame("\u{00B0}C", $sensor['unit']);
        self::assertSame($this->device1, $sensor['device']['@id']);
    }

    public function testIdentityIsEditableBeforeMeasurements(): void
    {
        $this->createSensor();
        self::assertSame('temp_b', $this->patch(['type' => 'temp_b'])['type']);
        self::assertSame('V', $this->patch(['unit' => 'V'])['unit']);
        self::assertSame($this->device2,
            $this->patch(['device' => $this->device2])['device']['@id']);
        $this->patch(['device' => $this->device1]);
    }

    public function testMeasurementsProtectAllIdentityFields(): void
    {
        $this->seedMeasuredSensor();
        foreach (['type' => 'temp_c', 'unit' => 'A', 'device' => $this->device2] as $field => $value) {
            $this->invalid('PATCH', $this->sensor, [$field => $value], $field);
        }
    }

    public function testLabelRemainsEditableAfterMeasurements(): void
    {
        $this->seedMeasuredSensor();
        self::assertSame('Renamed after measurement',
            $this->patch(['label' => 'Renamed after measurement'])['label']);
    }

    public function testUnchangedPersistedIdentityCanBeResubmitted(): void
    {
        $this->seedMeasuredSensor();
        $sensor = $this->patch(['device' => $this->device1, 'type' => 'temp_b',
            'unit' => 'V', 'label' => 'Still the same sensor']);
        self::assertSame('Still the same sensor', $sensor['label']);
    }

    public function testRejectedIdentityChangeDoesNotPersistLabel(): void
    {
        $this->seedMeasuredSensor();
        $this->patch(['label' => 'Still the same sensor']);
        $this->invalid('PATCH', $this->sensor,
            ['type' => 'temp_d', 'label' => 'Should never be persisted'], 'type');
        $sensor = $this->success('GET', $this->sensor);
        self::assertSame('Still the same sensor', $sensor['label']);
        self::assertSame('temp_b', $sensor['type']);
    }

    #[DataProvider('validationCases')]
    public function testNamedValidationGroups(string $uri, array $changes, string $property): void
    {
        $this->seedMeasuredSensor();
        $base = match ($uri) {
            '/api/sensors' => ['device' => $this->device1, 'type' => 'group-test', 'unit' => '%'],
            '/api/measurements' => ['sensor' => $this->sensor, 'value' => 12.3,
                'measuredAt' => '2020-01-01T00:00:00+00:00',
                'deduplicationId' => (string) Uuid::v4()],
            default => [],
        };
        foreach ($changes as $field => $value) {
            if (null === $value) {
                unset($base[$field]);
            } else {
                $base[$field] = $value;
            }
        }
        $this->invalid('POST', $uri, $base, $property);
    }

    #[DataProvider('readFields')]
    public function testReadGroupsExposeExactlyTheExistingFields(string $resource, array $expected): void
    {
        $this->seedMeasuredSensor();
        $body = $this->success('GET', $this->{$resource});
        $actual = array_values(array_filter(array_keys($body),
            static fn (string $field): bool => !str_starts_with($field, '@')));
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    public function testNestedAndReadOnlyFieldsStayProtected(): void
    {
        $this->seedMeasuredSensor();
        $sensor = $this->success('GET', $this->sensor);
        $measurement = $this->success('GET', $this->measurement);
        self::assertArrayNotHasKey('devEui', $sensor['device']);
        self::assertArrayNotHasKey('device', $measurement['sensor']);
        self::assertSame('temp_b', $measurement['type']);
        self::assertFalse(str_starts_with($measurement['createdAt'], '2999'));
    }

    public static function validationCases(): iterable
    {
        yield 'blank name' => ['/api/devices', ['name' => ''], 'name'];
        yield 'long name' => ['/api/devices', ['name' => str_repeat('a', 101)], 'name'];
        yield 'duplicate name' => ['/api/devices', ['name' => 'Identity device 1'], 'name'];
        yield 'duplicate EUI' => ['/api/devices', ['name' => 'Group test', 'devEui' => 'sid-dev-1'], 'devEui'];
        yield 'long EUI' => ['/api/devices', ['name' => 'Group test', 'devEui' => str_repeat('a', 33)], 'devEui'];
        yield 'missing device' => ['/api/sensors', ['device' => null], 'device'];
        yield 'blank type' => ['/api/sensors', ['type' => ''], 'type'];
        yield 'long unit' => ['/api/sensors', ['unit' => str_repeat('a', 21)], 'unit'];
        yield 'duplicate sensor' => ['/api/sensors', ['type' => 'temp_b', 'unit' => 'V'], 'device'];
        yield 'missing value' => ['/api/measurements', ['value' => null], 'value'];
        yield 'invalid UUID' => ['/api/measurements', ['deduplicationId' => 'not-a-uuid'], 'deduplicationId'];
        yield 'future date' => ['/api/measurements', ['measuredAt' => '2999-01-01T00:00:00+00:00'], 'measuredAt'];
    }

    public static function readFields(): iterable
    {
        yield 'device' => ['device1', ['id', 'name', 'devEui', 'description', 'createdAt']];
        yield 'sensor' => ['sensor', ['id', 'device', 'type', 'unit', 'label', 'createdAt']];
        yield 'measurement' => ['measurement', ['id', 'sensor', 'value', 'measuredAt', 'createdAt', 'deduplicationId', 'type']];
    }

    private function createSensor(): array
    {
        $sensor = $this->success('POST', '/api/sensors', ['device' => $this->device1,
            'type' => 'temp_a', 'unit' => "\u{00B0}C", 'label' => 'Sensor under test'], 201);
        $this->sensor = $sensor['@id'];

        return $sensor;
    }

    private function seedMeasuredSensor(): void
    {
        $sensor = $this->createSensor();
        $this->patch(['type' => 'temp_b']);
        $this->patch(['unit' => 'V']);
        $measurement = $this->success('POST', '/api/measurements', [
            'sensor' => $this->sensor, 'value' => 1.23,
            'measuredAt' => '2020-01-01T00:00:00+00:00',
            'deduplicationId' => (string) Uuid::v4(),
            'type' => 'must-not-be-written', 'createdAt' => '2999-01-01T00:00:00+00:00',
        ], 201);
        $this->measurement = $measurement['@id'];
        $this->measuredSensorId = (int) $sensor['id'];
    }

    private function patch(array $body): array
    {
        return $this->success('PATCH', $this->sensor, $body);
    }

    private function success(string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        [$response, $decoded] = $this->api->request($method, $uri, $body);
        self::assertSame($status, $response->getStatusCode(), $response->getContent());
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function invalid(string $method, string $uri, array $body, string $property): void
    {
        [$response, $decoded] = $this->api->request($method, $uri, $body);
        self::assertSame(422, $response->getStatusCode(), $response->getContent());
        self::assertContains($property, array_column($decoded['violations'] ?? [], 'propertyPath'));
    }
}