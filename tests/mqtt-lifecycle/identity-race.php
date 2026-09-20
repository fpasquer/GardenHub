<?php

declare(strict_types=1);

/*
 * MQTT auto-provisioning regression: a sensor's identity changes (via an
 * independent connection) between the handler resolving/expecting it and its
 * own locked re-read, mid message-processing. Proves the handler detects the
 * drift and throws instead of silently persisting against stale data, that
 * Messenger requeues the message for retry (not a silent drop, not a
 * dead-letter on the first attempt), and that the retry then succeeds
 * against the now-current identity - no manual EntityManager replacement,
 * kernel reboot, worker restart, or sleeps.
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

    // Pre-create the device + battery sensor the uplink will resolve.
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('identity-race-device', 'identity-race-device', NOW())");
    $raceDevice = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$raceDevice, 'battery', 'V', 'BatV']);
    $raceSensor = (int) $connection->lastInsertId();

    $dbParts = parse_url((string) getenv('DATABASE_URL'));
    $pdoDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbParts['host'], $dbParts['port'] ?? 3306, ltrim($dbParts['path'], '/'));
    $pdoUser = $dbParts['user'] ?? 'root';
    $pdoPass = $dbParts['pass'] ?? '';

    $raceId = (string) Uuid::v4();
    ingest($kernel, uplink(['BatV' => 3.6], 'identity-race-device', $raceId, 'Identity race sensor'));

    SensorRelabelMiddleware::arm(new SensorRelabelConfig($raceSensor, 'mV', $pdoDsn, $pdoUser, $pdoPass));

    $scenarioSubscriber = $container->get('test.scenario_subscriber');
    $scenarioSubscriber->armFailureCapture($raceId);

    $application->run(new ArrayInput([
        'command' => 'messenger:consume',
        'receivers' => ['async'],
        '--limit' => 2,
        '--time-limit' => 15,
    ]), new \Symfony\Component\Console\Output\BufferedOutput());

    $scenarioSubscriber->disarmFailureCapture();

    check(1 === $scenarioSubscriber->capturedFailureCount(), 'The first attempt must fail exactly once, detecting the mid-flight identity change. Got: '.$scenarioSubscriber->capturedFailureCount());
    $failureMessages = implode(' | ', $scenarioSubscriber->capturedFailureMessages());
    check(str_contains($failureMessages, 'identity changed'), 'The captured failure must be the identity-drift guard, not an unrelated error. Got: '.$failureMessages);
    check(1 === $scenarioSubscriber->capturedRetryCount(), 'Messenger must requeue the message for retry rather than dropping or dead-lettering it immediately.');

    $currentUnit = (string) $connection->fetchOne('SELECT unit FROM sensor WHERE id = ?', [$raceSensor]);
    check('mV' === $currentUnit, 'The concurrent relabel must have taken effect.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$raceId]), 'The retried attempt must persist the measurement once the identity is stable again.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL"), 'The queue must be empty after the retry succeeds.');

    echo "PASS identity-race: a mid-flight sensor identity change is detected and rejected on the first attempt, then the retry succeeds against the current identity\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
