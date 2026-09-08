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
 *
 * Not covered here (by design): worker lifecycle/reset behavior
 * (worker-lifecycle.php), deduplication (finding 6), and the MQTT
 * PUBACK → durable-enqueue loss window (documented in README).
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

function uplink(array $payload, string $time = '2020-01-01T12:00:00Z', string $devEui = 'delivery-device'): string
{
    return json_encode([
        'deviceInfo' => ['devEui' => $devEui, 'deviceName' => 'Delivery sensor'],
        'object' => $payload,
        'time' => $time,
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

    // Fresh schema. The Messenger transport table is NOT created here: the
    // Doctrine transport auto-creates it on first use, which this suite
    // implicitly validates. (Production uses the explicit migration instead,
    // see migrations/Version20260908195213.php.)
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schema = new SchemaTool($entityManager);
    $schema->dropSchema($metadata);
    $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $schema->createSchema($metadata);

    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
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

    ingest($kernel, uplink([
        'BatV' => 3.1,
        'water_SOIL' => 'not numeric',
        'temp_SOIL' => 19.2,
    ], time: '2020-06-01T12:00:00Z', devEui: 'mixed-device'));

    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume();

    check(2 === (int) $connection->fetchOne("SELECT COUNT(*) FROM measurement m JOIN sensor s ON s.id = m.sensor_id JOIN device d ON d.id = s.device_id WHERE d.dev_eui = 'mixed-device'"), 'Valid measurements of a mixed uplink must persist.');
    echo "PASS validation: per-measurement validation preserved over async transport\n";

    echo "PASS delivery guarantees: routing, retry, failure transport, recovery, validation\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
