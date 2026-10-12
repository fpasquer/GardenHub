<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Mqtt\ChirpStackUplink;
use App\Tests\Support\MqttDeliveryHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Uuid;
use Tests\MqttLifecycle\CollisionMiddleware;
use Tests\MqttLifecycle\CollisionConfig;
use Tests\MqttLifecycle\ScenarioRecorder;

#[Group('functional_gardenhub-mqtt-delivery')]
final class MqttDeliveryTest extends TestCase
{
    private MqttDeliveryHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new MqttDeliveryHarness();
    }

    protected function tearDown(): void
    {
        CollisionMiddleware::disarm();
        $this->harness->shutdown();
    }

    public function testRoutingIsDurableAndNotSynchronous(): void
    {
        [, $envelope] = $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.3]));
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertInstanceOf(ChirpStackUplink::class, $envelope->getMessage());
        $queued = $this->harness->connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'async'");
        self::assertIsArray($queued);
        self::assertStringContainsString('delivery-device', $queued['body']);
        self::assertSame(0, $this->storedCount("messenger_messages WHERE queue_name = 'sync'"));
        self::assertSame(0, $this->storedCount('measurement'));
    }

    public function testMalformedJsonIsLoggedAndNeverDispatched(): void
    {
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.3]));
        [$logger, $envelope] = $this->harness->ingest('{not-json');
        self::assertContains(['level' => 'error', 'message' => 'Received malformed JSON uplink; discarding.'], $logger->records);
        self::assertNull($envelope);
        self::assertSame(1, $this->storedCount('messenger_messages'));
    }

    public function testConsumptionPersistsAndProvisions(): void
    {
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.3]));
        $this->harness->consume();
        self::assertSame(1, $this->storedCount('measurement WHERE value = 3.3'));
        self::assertSame(1, $this->storedCount("device WHERE dev_eui = 'delivery-device'"));
        self::assertSame(0, $this->storedCount("messenger_messages WHERE queue_name = 'async'"));
    }

    public function testTemporaryFailureRequeuesAndRecovers(): void
    {
        $connection = $this->harness->connection;
        $connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT delivery_flush_failure CHECK (value <> 9999)');
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 9999]));
        $this->harness->consume();
        self::assertSame(0, $this->storedCount('measurement WHERE value = 9999'));
        self::assertIsArray($connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"));
        $connection->executeStatement('ALTER TABLE measurement DROP CONSTRAINT delivery_flush_failure');
        $this->harness->makeAvailable();
        $this->harness->consume();
        self::assertSame(1, $this->storedCount('measurement WHERE value = 9999'));
        self::assertSame(0, $this->storedCount("messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"));
    }

    public function testExhaustionAlertsOnlyAfterDurableFailure(): void
    {
        $id = (string) Uuid::v4();
        $this->harness->connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT delivery_flush_failure CHECK (value <> 8888)');
        $container = $this->harness->kernel->getContainer();
        $subscriber = $container->get('test.scenario_subscriber');
        $subscriber->armFailureCapture($id);
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 8888],
            device: 'failed-device', id: $id, name: 'Failed sensor'));
        $transport = $container->get('test.telegram_transport');
        $calls = [];
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->harness->makeAvailable();
            $this->harness->consume();
            $calls[] = count($transport->calls);
        }
        $subscriber->disarmFailureCapture();
        self::assertSame(4, $subscriber->capturedFailureCount());
        self::assertSame(3, $subscriber->capturedRetryCount());
        foreach ($subscriber->capturedFailureMessages() as $message) {
            self::assertStringContainsString('delivery_flush_failure', $message);
        }
        self::assertSame([0, 0, 0, 1], $calls);
        $this->assertTerminalFailure($id, $transport->calls);
        $shown = $this->harness->console(['command' => 'messenger:failed:show']);
        self::assertTrue(str_contains($shown, 'failed-device') || str_contains($shown, 'ChirpStackUplink'));
    }

    public function testMixedUplinkPreservesPerMeasurementValidation(): void
    {
        $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.1,
            'water_SOIL' => 'not numeric', 'temp_SOIL' => 19.2],
            time: '2020-06-01T12:00:00Z', device: 'mixed-device'));
        $this->harness->makeAvailable();
        $this->harness->consume();
        self::assertSame(2, (int) $this->harness->connection->fetchOne("SELECT COUNT(*) FROM measurement m JOIN sensor s ON s.id = m.sensor_id JOIN device d ON d.id = s.device_id WHERE d.dev_eui = 'mixed-device'"));
    }

    public function testSequentialReplayIsAcknowledgedWithoutDuplicates(): void
    {
        $id = (string) Uuid::v4();
        $payload = MqttDeliveryHarness::uplink(['BatV' => 3.2, 'temp_SOIL' => 18.5],
            device: 'replay-device', id: $id, name: null);
        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $this->harness->ingest($payload);
            $this->harness->makeAvailable();
            $this->harness->consume();
            self::assertSame(2, (int) $this->harness->connection->fetchOne(
                'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$id]));
        }
        self::assertSame(0, $this->storedCount("messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"));
        self::assertSame(0, $this->storedCount("messenger_messages WHERE queue_name = 'failed' AND body LIKE '%replay-device%'"));
    }

    public function testMissingAndInvalidDeduplicationIdsAreDiscarded(): void
    {
        [$logger, $envelope] = $this->harness->ingest(MqttDeliveryHarness::uplink(['BatV' => 3.3], device: 'noid-device', id: 'not-a-uuid'));
        self::assertContains(['level' => 'warning', 'message' => 'Uplink without valid deduplicationId ignored.'], $logger->records);
        self::assertNull($envelope);
        [, $envelope] = $this->harness->ingest(json_encode([
            'deviceInfo' => ['devEui' => 'noid-device', 'deviceName' => 'No Id'],
            'object' => ['BatV' => 3.3], 'time' => '2020-01-01T12:00:00Z',
        ], JSON_THROW_ON_ERROR));
        self::assertNull($envelope);
    }

    public function testCollisionRetriesAndProcessesEventBInSameConsumer(): void
    {
        $id = (string) Uuid::v4();
        $sensorId = $this->createCollisionSensors();
        $this->harness->ingest(MqttDeliveryHarness::uplink(
            ['BatV' => 3.6, 'temp_SOIL' => 20.1], device: 'collision-device',
            id: $id, name: 'Collision sensor'));
        $parts = parse_url((string) getenv('DATABASE_URL'));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parts['host'], $parts['port'] ?? 3306, ltrim($parts['path'], '/'));
        CollisionMiddleware::arm(new CollisionConfig($sensorId, $id, $dsn,
            $parts['user'] ?? 'root', $parts['pass'] ?? ''));
        $eventBId = (string) Uuid::v4();
        $eventB = MqttDeliveryHarness::uplink(['BatV' => 3.7],
            device: 'collision-device', id: $eventBId, name: null);
        ScenarioRecorder::reset();
        $subscriber = $this->harness->kernel->getContainer()->get('test.scenario_subscriber');
        $subscriber->arm($id, $eventB);
        try {
            $this->harness->consume(3);
        } finally {
            CollisionMiddleware::disarm();
            $subscriber->disarm();
        }
        $sequence = ['a_reached_flush_after_pre_check', 'collision_row_committed',
            'a_flush_failed_unique_violation', 'a_em_closed_after_failed_flush',
            'a_retry_scheduled', 'a_retry_succeeded', 'b_enqueued', 'b_succeeded'];
        $indices = array_map(ScenarioRecorder::indexOf(...), $sequence);
        $sorted = $indices;
        sort($sorted);
        self::assertSame($sorted, $indices);
        self::assertCount(1, array_filter(ScenarioRecorder::all(),
            static fn (array $event): bool => 'collision_row_committed' === $event[0]));
        $this->assertCollisionRows($id, $eventBId);
    }

    private function createCollisionSensors(): int
    {
        $connection = $this->harness->connection;
        $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('collision-device', 'collision-device', NOW())");
        $device = (int) $connection->lastInsertId();
        $sql = 'INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())';
        $connection->executeStatement($sql, [$device, 'battery', 'V', 'BatV']);
        $sensor = (int) $connection->lastInsertId();
        $connection->executeStatement($sql,
            [$device, 'soil_temperature', "\u{00B0}C", 'temp_SOIL']);

        return $sensor;
    }

    private function assertCollisionRows(string $id, string $eventBId): void
    {
        $connection = $this->harness->connection;
        self::assertSame(9.9, (float) $connection->fetchOne(
            'SELECT value FROM measurement WHERE deduplication_id = ? AND type = ?', [$id, 'battery']));
        foreach (['battery', 'soil_temperature'] as $type) {
            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND type = ?', [$id, $type]));
        }
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$eventBId]));
        foreach (['async', 'failed'] as $queue) {
            self::assertSame(0, (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE '%collision-device%'", [$queue]));
        }
    }

    private function assertTerminalFailure(string $id, array $calls): void
    {
        $connection = $this->harness->connection;
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE ?", ['%'.$id.'%']));
        self::assertCount(1, $calls);
        $failed = $connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE ?", ['%'.$id.'%']);
        self::assertStringContainsString('failed-device', $failed['body']);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ?", ['%'.$id.'%']));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$id]));
        foreach ([ChirpStackUplink::class, 'Retry count: 3', $id] as $text) {
            self::assertStringContainsString($text, $calls[0]['text']);
        }
        self::assertStringNotContainsString('delivery_flush_failure', $calls[0]['text']);
    }

    private function storedCount(string $table): int
    {
        return (int) $this->harness->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}