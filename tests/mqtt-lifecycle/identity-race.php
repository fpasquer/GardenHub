<?php

declare(strict_types=1);

/*
 * MQTT auto-provisioning regression: a sensor's identity changes (via an
 * independent connection) between the handler resolving/expecting it and its
 * own locked re-read, mid message-processing. Proves the handler detects the
 * drift and throws instead of silently persisting against stale data, that
 * Messenger requeues the message for retry (not a silent drop, not a
 * dead-letter on the first attempt), and both retry outcomes: a sensor left
 * incompatible with its field mapping keeps failing through every retry and
 * lands in the failed queue (no manual EntityManager replacement, kernel
 * reboot, worker restart, or sleeps), while a sensor restored to a
 * compatible identity before the retry succeeds normally.
 */

use App\Command\MqttConsumeCommand;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\AbstractLogger;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Tests\MqttLifecycle\ScenarioSubscriber;
use Tests\MqttLifecycle\SensorRelabelConfig;
use Tests\MqttLifecycle\SensorRelabelMiddleware;

require '/app/vendor/autoload.php';

// Test-only classes under /tests/src are not in the Composer autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\MqttLifecycle\\';
    if (str_starts_with($class, $prefix)) {
        require '/tests/src/'.substr($class, strlen($prefix)).'.php';
    }
});

final class IdentityRaceKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-identity-race/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-identity-race/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        // Test-only: shorten the async retry delay so the forced identity
        // race is retried promptly in-process. Production settings untouched.
        $loader->load('/tests/config/packages/messenger_test_retry.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Test-only relabel hook on the shared default DBAL connection (used
        // by both the ORM entity manager and the Messenger doctrine transport).
        $container->register('test.sensor_relabel_middleware', SensorRelabelMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.services_resetter', 'services_resetter')->setPublic(true);

        // Reused as-is: its generic armFailureCapture()/capturedFailureCount()/
        // capturedRetryCount()/capturedFailureMessages() API observes any
        // failure/retry for one deduplicationId, not only the collision case
        // it was originally written for.
        $container->register('test.scenario_subscriber', ScenarioSubscriber::class)
            ->setArguments([new Reference('messenger.default_bus'), new Reference('doctrine.orm.entity_manager')])
            ->addTag('kernel.event_subscriber')
            ->setPublic(true);
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
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uplink(array $payload, string $devEui, string $deduplicationId, string $deviceName): string
{
    return json_encode([
        'deviceInfo' => ['devEui' => $devEui, 'deviceName' => $deviceName],
        'object' => $payload,
        'time' => '2020-01-01T12:00:00Z',
        'deduplicationId' => $deduplicationId,
    ], JSON_THROW_ON_ERROR);
}

/**
 * Feeds one raw MQTT payload through the command's parsing/dispatch path.
 *
 * @return array{CollectingLogger, Envelope|null}
 */
function ingest(IdentityRaceKernel $kernel, string $mqttPayload): array
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

    $command = new MqttConsumeCommand($observedBus, $logger, 'mqtt', 1883, '', '', 'identity-race-test', 'tests/identity-race', $resetter);

    $method = new ReflectionMethod(MqttConsumeCommand::class, 'handleMessage');
    $method->invoke($command, 'tests/identity-race/topic', $mqttPayload);

    return [$logger, $observedBus->envelope];
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new IdentityRaceKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    check('worker_lifecycle' === $connection->getDatabase(), 'Refusing to modify a non-test database.');

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
            '--time-limit' => 15,
        ]), $output);

        return $output->fetch();
    };

    $dbParts = parse_url((string) getenv('DATABASE_URL'));
    $pdoDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbParts['host'], $dbParts['port'] ?? 3306, ltrim($dbParts['path'], '/'));
    $pdoUser = $dbParts['user'] ?? 'root';
    $pdoPass = $dbParts['pass'] ?? '';

    $scenarioSubscriber = $container->get('test.scenario_subscriber');

    // -----------------------------------------------------------------
    // A. Persistent incompatibility: the field map expects unit 'V', but a
    //    concurrent relabel to 'mV' is never reverted. The first attempt
    //    fails on the resolved-vs-locked check (the relabel lands mid-flight);
    //    every subsequent retry must keep failing too, now on the mapped-
    //    identity compatibility check, so a mismatched unit is never
    //    silently accepted - the message is exhausted to the failed queue.
    // -----------------------------------------------------------------
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('identity-race-device-a', 'identity-race-device-a', NOW())");
    $deviceA = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$deviceA, 'battery', 'V', 'BatV']);
    $sensorA = (int) $connection->lastInsertId();

    $persistentId = (string) Uuid::v4();
    ingest($kernel, uplink(['BatV' => 3.6], 'identity-race-device-a', $persistentId, 'Persistent incompatibility sensor'));

    SensorRelabelMiddleware::arm(new SensorRelabelConfig($sensorA, 'mV', $pdoDsn, $pdoUser, $pdoPass));
    $scenarioSubscriber->armFailureCapture($persistentId);

    for ($attempt = 0; $attempt < 4; ++$attempt) {
        $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
        $consume();
    }

    $scenarioSubscriber->disarmFailureCapture();

    check(4 === $scenarioSubscriber->capturedFailureCount(), 'A persistent incompatibility must fail every attempt, including every retry. Got: '.$scenarioSubscriber->capturedFailureCount());
    check(3 === $scenarioSubscriber->capturedRetryCount(), 'Exactly 3 retries must be scheduled before the message is sent to the failed transport.');
    $persistentMessages = $scenarioSubscriber->capturedFailureMessages();
    check(str_contains($persistentMessages[0], 'identity changed'), 'The first attempt must fail on the resolved-vs-locked check (the relabel lands mid-attempt). Got: '.$persistentMessages[0]);
    foreach (array_slice($persistentMessages, 1) as $laterMessage) {
        check(str_contains($laterMessage, 'incompatible'), 'Every attempt after the relabel must fail the mapped-identity compatibility check. Got: '.$laterMessage);
    }
    check(1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE ?", ['%'.$persistentId.'%']), 'The exhausted message must land in the failed queue.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ?", ['%'.$persistentId.'%']), 'No async-queue message may remain for this event.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$persistentId]), 'A persistently incompatible sensor must never have a measurement stored.');
    echo "PASS persistent-incompatibility: a sensor relabeled out of its mapping stays rejected through every retry and lands in the failed queue\n";

    // -----------------------------------------------------------------
    // B. Recovery: the same kind of relabel happens, but a separate write
    //    restores the mapped identity before the retry runs, so the retry
    //    must succeed once the identity is compatible again.
    // -----------------------------------------------------------------
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('identity-race-device-b', 'identity-race-device-b', NOW())");
    $deviceB = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$deviceB, 'battery', 'V', 'BatV']);
    $sensorB = (int) $connection->lastInsertId();

    $recoveryId = (string) Uuid::v4();
    ingest($kernel, uplink(['BatV' => 3.6], 'identity-race-device-b', $recoveryId, 'Recovery sensor'));

    SensorRelabelMiddleware::arm(new SensorRelabelConfig($sensorB, 'mV', $pdoDsn, $pdoUser, $pdoPass));
    $scenarioSubscriber->armFailureCapture($recoveryId);

    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume();

    // Restore compatibility via a separate write, exactly like the relabel
    // itself, before the retry is allowed to run.
    $connection->executeStatement('UPDATE sensor SET unit = ? WHERE id = ?', ['V', $sensorB]);
    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    $consume();

    $scenarioSubscriber->disarmFailureCapture();

    check(1 === $scenarioSubscriber->capturedFailureCount(), 'Only the first attempt may fail once the identity is restored before the retry. Got: '.$scenarioSubscriber->capturedFailureCount());
    check(1 === $scenarioSubscriber->capturedRetryCount(), 'Exactly one retry must be scheduled.');
    check(str_contains($scenarioSubscriber->capturedFailureMessages()[0], 'identity changed'), 'The failed attempt must be the resolved-vs-locked guard.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ? AND value = 3.6 AND type = ?', [$recoveryId, 'battery']), 'The retry must persist the measurement once the identity is compatible again.');
    check('V' === (string) $connection->fetchOne('SELECT unit FROM sensor WHERE id = ?', [$sensorB]), 'The sensor must be back to its mapped unit.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ?", ['%'.$recoveryId.'%']), 'The queue must be empty after the retry succeeds.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE ?", ['%'.$recoveryId.'%']), 'A recovered event must never reach the failed queue.');
    echo "PASS recovery: once the sensor identity is restored, the retry succeeds\n";

    echo "PASS identity-race: a persistent incompatibility keeps rejecting through retry exhaustion, and a recovered identity lets the retry succeed\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
