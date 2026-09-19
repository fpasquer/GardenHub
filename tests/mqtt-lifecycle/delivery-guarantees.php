<?php

declare(strict_types=1);

/*
 * Delivery-guarantee regressions for the MQTT → Messenger pipeline.
 *
 * Covered behaviors:
 *  - ChirpStackUplink is routed to the Doctrine async transport
 *  - successful consumption persists measurements (and auto-provisions)
 *  - per-measurement validation behavior is preserved
 *  - temporary handler failure leaves the message queued for retry
 *  - retry exhaustion moves the message to the failed queue
 *  - a requeued message recovers once the downstream failure is gone
 *  - malformed MQTT JSON is logged and discarded (no Messenger row)
 *  - a replayed uplink is stored at most once per (deduplicationId, type)
 *  - a concurrent unique-constraint collision is retried, not lost
 *
 * Not covered here (by design): worker lifecycle/reset behavior
 * (worker-lifecycle.php) and the MQTT PUBACK → durable-enqueue loss window
 * (documented in README).
 */

use App\Command\MqttConsumeCommand;
use App\Kernel;
use App\Mqtt\ChirpStackUplink;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';

final class DeliveryKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-delivery/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-delivery/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.services_resetter', 'services_resetter')->setPublic(true);
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }

    public function has(string $level, string $needle): bool
    {
        foreach ($this->records as [$recordLevel, $message]) {
            if ($level === $recordLevel && str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uplink(array $payload, string $time = '2020-01-01T12:00:00Z', string $devEui = 'delivery-device', ?string $deduplicationId = null): string
{
    return json_encode([
        'deviceInfo' => ['devEui' => $devEui, 'deviceName' => 'Delivery sensor'],
        'object' => $payload,
        'time' => $time,
        'deduplicationId' => $deduplicationId ?? (string) Uuid::v4(),
    ], JSON_THROW_ON_ERROR);
}

/**
 * Feeds one raw MQTT payload through the command's parsing/dispatch path.
 * Returns the command logs and the dispatched envelope (if any).
 *
 * @return array{CollectingLogger, Envelope|null}
 */
function ingest(DeliveryKernel $kernel, string $mqttPayload): array
{
    $container = $kernel->getContainer();
    $bus = $container->get('test.messenger.default_bus');
    $resetter = $container->get('test.services_resetter');
    $logger = new CollectingLogger();

    $envelope = null;
    $observedBus = new class($bus, $envelope) implements MessageBusInterface {
        public ?Envelope $envelope = null;

        public function __construct(private readonly MessageBusInterface $bus)
        {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            return $this->envelope = $this->bus->dispatch($message, $stamps);
        }
    };

    $command = new MqttConsumeCommand($observedBus, $logger, 'mqtt', 1883, '', '', 'delivery-test', 'tests/delivery', $resetter);

    $method = new ReflectionMethod(MqttConsumeCommand::class, 'handleMessage');
    $method->invoke($command, 'tests/delivery/topic', $mqttPayload);

    return [$logger, $observedBus->envelope];
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new DeliveryKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    check('worker_lifecycle' === $connection->getDatabase(), 'Refusing to modify a non-test database.');

    // Fresh entity schema. Note: createSchema() also creates the
    // messenger_messages table via Symfony's Messenger Doctrine schema
    // listener. It is dropped again right after, so the transport table is
    // created explicitly by the production migration instead — matching
    // messenger.yaml (auto_setup: false).
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schema = new SchemaTool($entityManager);
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);
    $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');

    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $migrationOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    $migrationExit = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:execute',
        'versions' => ['DoctrineMigrations\\Version20260908195213'],
        '--up' => true,
        '--no-interaction' => true,
    ]), $migrationOutput);
    check(0 === $migrationExit, 'Messenger migration failed: '.$migrationOutput->fetch());
    // The migration's transactional DDL implicitly commits underneath DBAL,
    // leaving the shared connection's transaction tracking inconsistent.
    // Reconnect before handing the connection to the Messenger transport.
    $connection->close();
    $consume = static function (int $limit = 1) use ($application): string {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $application->run(new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async'],
            '--limit' => $limit,
            '--time-limit' => 30,
        ]), $output);

        return $output->fetch();
    };

    // -----------------------------------------------------------------
    // 1. Routing: a valid uplink is durably enqueued to the async queue.
    // -----------------------------------------------------------------
    [, $envelope] = ingest($kernel, uplink(['BatV' => 3.3]));

    check($envelope instanceof Envelope, 'The uplink must produce a Messenger envelope.');
    check($envelope->getMessage() instanceof ChirpStackUplink, 'The dispatched message must be a ChirpStackUplink.');

    // The durable enqueue IS the routing guarantee: the message body in the
    // async queue proves the routing rule sent it to the Doctrine transport.
    $queued = $connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'async'");
    check(false !== $queued, 'The uplink must be durably stored in the async queue.');
    check(str_contains($queued['body'], 'delivery-device'), 'The queued body must contain the uplink payload.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'sync'"), 'The uplink must not be handled synchronously.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement'), 'No measurement may be persisted before the consumer runs.');
    echo "PASS routing: ChirpStackUplink durably enqueued to async transport\n";

    // -----------------------------------------------------------------
    // 2. Malformed MQTT JSON: logged, discarded, never dispatched.
    // -----------------------------------------------------------------
    [$logs, $envelope] = ingest($kernel, '{not-json');
    check($logs->has('error', 'malformed JSON uplink'), 'Malformed JSON must be logged as an explicit error.');
    check(null === $envelope, 'Malformed JSON must not be dispatched.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'), 'Malformed MQTT JSON must not create a Messenger message.');
    echo "PASS malformed: invalid MQTT JSON logged and discarded at the boundary\n";

    // -----------------------------------------------------------------
    // 3. Consumption persists measurements and auto-provisions.
    // -----------------------------------------------------------------
    $consume();
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value = 3.3'), 'The queued uplink must persist after consumption.');
    check(1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM device WHERE dev_eui = 'delivery-device'"), 'Auto-provisioning must create the device.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'"), 'The queue must be empty after successful processing.');
    echo "PASS consume: queued uplink persisted with auto-provisioning\n";

    // -----------------------------------------------------------------
    // 4. Handler-time failure: message stays queued, retry recovers it.
    // -----------------------------------------------------------------
    $connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT delivery_flush_failure CHECK (value <> 9999)');
    ingest($kernel, uplink(['BatV' => 9999]));

    $output = $consume();
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value = 9999'), 'A failing handler must not persist partial measurements.');
    check(false !== $connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"), 'A temporary handler failure must leave the message queued for retry.');

    // Remove the failure condition; the retry must recover persistence.
    $connection->executeStatement('ALTER TABLE measurement DROP CONSTRAINT delivery_flush_failure');
    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume();

    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value = 9999'), 'The retried message must persist after recovery.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"), 'The queue must be empty after the retry succeeds.');
    echo "PASS retry: temporary handler failure requeued and recovered\n";

    // -----------------------------------------------------------------
    // 5. Retry exhaustion: message lands in the failed queue.
    // -----------------------------------------------------------------
    $connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT delivery_flush_failure CHECK (value <> 8888)');
    ingest($kernel, uplink(['BatV' => 8888], devEui: 'failed-device'));

    // Force every retry to be immediately available, then burn through them.
    for ($attempt = 0; $attempt < 4; ++$attempt) {
        $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
        $consume();
    }

    $failed = $connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'failed'");
    check(false !== $failed, 'After retry exhaustion the message must move to the failed queue.');
    check(str_contains($failed['body'], 'failed-device'), 'The failed queue must contain the rejected uplink.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value = 8888'), 'A permanently failing message must not be persisted.');
    echo "PASS failure: exhausted retries moved the message to the failed queue\n";

    // -----------------------------------------------------------------
    // 6. Failed messages are inspectable with messenger:failed:show.
    //    (messenger:failed:retry re-handles the message immediately; the
    //    recovery-after-retry path is covered by scenario 4 above.)
    // -----------------------------------------------------------------
    $showOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    $application->run(new ArrayInput(['command' => 'messenger:failed:show']), $showOutput);
    $shown = $showOutput->fetch();
    check(str_contains($shown, 'failed-device') || str_contains($shown, 'ChirpStackUplink'), 'messenger:failed:show must list the failed uplink.');
    echo "PASS failed-inspect: failed messages are inspectable via messenger:failed:show\n";

    // -----------------------------------------------------------------
    // 7. Per-measurement validation is preserved through the async path.
    //    Reset the schema to clear identity-map references to rows from the
    //    failed-device scenario (the check constraint disappears with it).
    // -----------------------------------------------------------------
    $entityManager->clear();
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);
    // The schema reset above is DDL; the shared connection can be left in an
    // aborted transaction after scenario 5's failed flush, so reconnect before
    // the following scenarios rely on committed reads.
    $connection->close();

    ingest($kernel, uplink([
        'BatV' => 3.1,
        'water_SOIL' => 'not numeric',
        'temp_SOIL' => 19.2,
    ], time: '2020-06-01T12:00:00Z', devEui: 'mixed-device'));

    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume();

    check(2 === (int) $connection->fetchOne("SELECT COUNT(*) FROM measurement m JOIN sensor s ON s.id = m.sensor_id JOIN device d ON d.id = s.device_id WHERE d.dev_eui = 'mixed-device'"), 'Valid measurements of a mixed uplink must persist.');
    echo "PASS validation: per-measurement validation preserved over async transport\n";

    // -----------------------------------------------------------------
    // 8. Sequential replay: the same uplink delivered twice is stored once.
    //    The pre-check no-ops the second delivery (no exception, fast path).
    //    Scenario 7's schema reset left the queue empty and the connection
    //    reconnected, so the only async message is the replay one.
    // -----------------------------------------------------------------
    $replayId = (string) Uuid::v4();
    // name:null keeps the placeholder device name (replay-device) so the
    // placeholder→name upgrade cannot collide with another device's unique name.
    $replay = json_encode([
        'deviceInfo' => ['devEui' => 'replay-device'],
        'object' => ['BatV' => 3.2, 'temp_SOIL' => 18.5],
        'time' => '2020-01-01T12:00:00Z',
        'deduplicationId' => $replayId,
    ], JSON_THROW_ON_ERROR);
    $replayRow = "SELECT COUNT(*) FROM measurement WHERE deduplication_id = '$replayId'";

    ingest($kernel, $replay);
    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume(1);
    check(2 === (int) $connection->fetchOne($replayRow), 'First delivery must store both mapped types.');

    // Re-deliver the identical raw payload (same deduplicationId).
    ingest($kernel, $replay);
    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume(1);

    check(2 === (int) $connection->fetchOne($replayRow), 'A replayed uplink must not duplicate measurements.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"), 'The replay must be acknowledged, not left queued.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE '%replay-device%'"), 'A replayed uplink must not reach the failed queue.');
    echo "PASS replay: identical uplink stored once per (deduplicationId, type)\n";

    // -----------------------------------------------------------------
    // 9. Boundary: an uplink with a missing or invalid deduplicationId is
    //    logged and discarded, never dispatched.
    // -----------------------------------------------------------------
    [$logs, $envelope] = ingest($kernel, uplink(['BatV' => 3.3], devEui: 'noid-device', deduplicationId: 'not-a-uuid'));
    check($logs->has('warning', 'valid deduplicationId'), 'An invalid deduplicationId must be logged as a warning.');
    check(null === $envelope, 'An uplink without a valid deduplicationId must not be dispatched.');

    $noId = json_encode(['deviceInfo' => ['devEui' => 'noid-device', 'deviceName' => 'No Id'], 'object' => ['BatV' => 3.3], 'time' => '2020-01-01T12:00:00Z'], JSON_THROW_ON_ERROR);
    [, $envelope] = ingest($kernel, $noId);
    check(null === $envelope, 'An uplink missing deduplicationId must not be dispatched.');
    echo "PASS boundary: missing/invalid deduplicationId logged and discarded\n";

    // -----------------------------------------------------------------
    // 10. Concurrent collision: a conflicting row committed AFTER the
    //     pre-check but BEFORE the flush makes the consumer's flush hit the
    //     unique index and throw. The retry's pre-check then tops up only the
    //     missing type. Proves recovery from a real failed flush.
    //
    //     Deterministic and single-process: the handler runs on a SECOND DB
    //     connection/EM. A one-shot flush decorator injects the conflicting
    //     (deduplicationId, battery) row from the MAIN connection immediately
    //     before the real flush — after the handler's pre-check ran.
    // -----------------------------------------------------------------
    $collisionId = (string) Uuid::v4();

    // Pre-create device + battery sensor on the main connection.
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('collision-device', 'collision-device', NOW())");
    $collisionDevice = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$collisionDevice, 'battery', 'V', 'BatV']);
    $collisionSensor = (int) $connection->lastInsertId();

    $collisionRegistry = new class implements \Doctrine\Persistence\ManagerRegistry {
        public \Doctrine\DBAL\Connection $connection;
        public \Doctrine\ORM\EntityManagerInterface $em;
        public function getConnection(?string $name = null): object { return $this->connection; }
        public function getConnections(): array { return ['default' => $this->connection]; }
        public function getConnectionNames(): array { return ['default' => 'default']; }
        public function getDefaultConnectionName(): string { return 'default'; }
        public function getManager(?string $name = null): \Doctrine\Persistence\ObjectManager { return $this->em; }
        public function getManagers(): array { return ['default' => $this->em]; }
        public function getManagerForClass(string $class): ?\Doctrine\Persistence\ObjectManager { return $this->em; }
        public function getManagerNames(): array { return ['default' => 'default']; }
        public function getDefaultManagerName(): string { return 'default'; }
        public function getRepository(string $persistentObject, ?string $persistentManagerName = null): \Doctrine\Persistence\ObjectRepository { return $this->em->getRepository($persistentObject); }
        public function resetManager(?string $name = null): \Doctrine\Persistence\ObjectManager { return $this->em; }
        public function getAliasNamespace($alias): string { return 'App\Entity'; }
    };
    $dsnParams = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
    $collisionConfig = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(['/app/src/Entity'], true);
    $collisionConfig->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy());
    $collisionRegistry->connection = \Doctrine\DBAL\DriverManager::getConnection($dsnParams);
    $collisionRegistry->em = new \Doctrine\ORM\EntityManager($collisionRegistry->connection, $collisionConfig);

    $buildHandler = fn (\Doctrine\ORM\EntityManagerInterface $em): \App\Mqtt\ChirpStackUplinkHandler => new \App\Mqtt\ChirpStackUplinkHandler(
        $em,
        new \App\Repository\DeviceRepository($collisionRegistry),
        new \App\Repository\SensorRepository($collisionRegistry),
        \Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        new \Psr\Log\NullLogger(),
        ['BatV' => ['type' => 'battery', 'unit' => 'V'], 'temp_SOIL' => ['type' => 'soil_temperature', 'unit' => '°C']],
    );

    $collisionMessage = fn (string $id): ChirpStackUplink => new ChirpStackUplink(
        'collision-device', ['BatV' => 3.6, 'temp_SOIL' => 20.1], new \DateTimeImmutable('2020-01-01T12:00:00Z'), $collisionId
    );

    // Attempt 1 on the second connection: inject the conflicting battery row
    // right after the pre-check, before the flush. The flush must throw the
    // unique-constraint violation and store nothing new.
    $injected = false;
    $em1 = new class($collisionRegistry->em, $connection, $collisionSensor, $collisionId, $injected) extends \Doctrine\ORM\Decorator\EntityManagerDecorator {
        public function __construct(\Doctrine\ORM\EntityManagerInterface $wrapped, private \Doctrine\DBAL\Connection $main, private int $sensorId, private string $dedupId, private bool &$injected) { parent::__construct($wrapped); }
        public function flush($entity = null): void
        {
            if (!$this->injected) {
                $this->injected = true;
                // Competing commit: (deduplicationId, battery) lands now, after
                // the handler's pre-check (which already ran against this EM).
                $this->main->executeStatement('INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (?, ?, ?, NOW(), ?, ?)', [$this->sensorId, 9.9, '2020-01-01 12:00:00', $this->dedupId, 'battery']);
            }
            parent::flush($entity);
        }
    };

    $collisionError = null;
    try {
        $buildHandler($em1)($collisionMessage($collisionId));
    } catch (\Throwable $e) {
        $collisionError = $e;
    }
    check(null !== $collisionError, 'The concurrent flush must throw.');
    check(
        str_contains($collisionError->getMessage(), 'uniq_measurement_dedup_type') || str_contains($collisionError->getMessage(), '1062') || str_contains($collisionError->getMessage(), 'Duplicate entry'),
        'The failure must be the unique (deduplication_id, type) violation. Got: '.$collisionError->getMessage()
    );
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND type = ?', [$collisionId, 'battery']), 'Only the competing battery row must exist after the failed flush.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND type = ?', [$collisionId, 'soil_temperature']), 'A failed flush must not partially persist soil_temperature.');

    // Attempt 2 (retry) on a FRESH second connection/EM (the previous one is
    // closed after the failed flush). Pre-check must skip battery, store
    // soil_temperature, and not throw.
    $collisionRegistry->connection = \Doctrine\DBAL\DriverManager::getConnection($dsnParams);
    $collisionConfig2 = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(['/app/src/Entity'], true);
    $collisionConfig2->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy());
    $collisionRegistry->em = new \Doctrine\ORM\EntityManager($collisionRegistry->connection, $collisionConfig2);
    $retryError = null;
    try {
        $buildHandler($collisionRegistry->em)($collisionMessage($collisionId));
    } catch (\Throwable $e) {
        $retryError = $e;
    }
    check(null === $retryError, 'The retry must succeed. Got: '.($retryError?->getMessage() ?? 'none'));

    $collisionTypes = $connection->fetchFirstColumn('SELECT DISTINCT type FROM measurement WHERE deduplication_id = ?', [$collisionId]);
    sort($collisionTypes);
    check(['battery', 'soil_temperature'] === $collisionTypes, 'Retry must top up the missing type exactly once. Got: '.implode(',', $collisionTypes));
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND type = ?', [$collisionId, 'battery']), 'battery must remain stored exactly once.');
    echo "PASS collision: failed flush retried, missing type topped up, exactly-once per (deduplicationId, type)\n";

    echo "PASS delivery guarantees: routing, retry, failure transport, recovery, validation, replay, boundary, collision\n";
    $kernel->shutdown();
    exit(0);

    echo "PASS delivery guarantees: routing, retry, failure transport, recovery, validation, replay, boundary, collision\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
