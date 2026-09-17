<?php

declare(strict_types=1);

/*
 * Integration test: QoS 1 uplinks queued by Mosquitto for an offline
 * persistent session must reach the Doctrine async queue when a FRESH worker
 * process reconnects with the same client ID.
 *
 * This complements pre-suback-delivery.php: that test forces the
 * PUBLISH-before-SUBACK ordering deterministically against a stub broker,
 * while this test verifies the real resumed-session flow end to end without
 * relying on packet ordering (Mosquitto's exact resumption scheduling is not
 * a protocol guarantee).
 */

use App\Command\MqttConsumeCommand;
use App\Kernel;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require '/app/vendor/autoload.php';

const CLIENT_ID = 'tests-session-resume';
const TOPIC = 'tests/session-resume/up';
const MESSAGE_COUNT = 5;

final class SessionResumeKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-session-resume/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-session-resume/log';
    }

    // No messenger_sync.yaml override here: ChirpStackUplink must be routed
    // to the real Doctrine async transport, and no consumer is running so
    // the queue contents can be inspected.

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.services_resetter', 'services_resetter')->setPublic(true);
    }
}

final class NullWorkerLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pollUntil(callable $condition, float $seconds, string $message): void
{
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(50000);
    }
}

function workerProcess(SessionResumeKernel $kernel): int
{
    $container = $kernel->getContainer();
    $command = new MqttConsumeCommand(
        $container->get('test.messenger.default_bus'),
        new NullWorkerLogger(),
        'mqtt',
        1883,
        '',
        '',
        CLIENT_ID,
        TOPIC,
        $container->get('test.services_resetter'),
    );

    // Runs until the parent terminates this process.
    return $command->run(new ArrayInput([]), new NullOutput());
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new SessionResumeKernel('dev', true);
    $kernel->boot();

    if (isset($argv[1]) && 'worker' === $argv[1]) {
        exit(workerProcess($kernel));
    }

    $connection = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
    check('worker_lifecycle' === $connection->getDatabase(), 'Refusing to modify a non-test database.');

    // Purge any stale broker session for this client ID (previous runs).
    $purge = new MqttClient('mqtt', 1883, CLIENT_ID);
    $purge->connect(new ConnectionSettings(), true);
    $purge->disconnect();

    // Prepare the Messenger transport table explicitly via the production
    // migration; the transport has auto_setup disabled (messenger.yaml).
    $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $migrationOutput = new BufferedOutput();
    $migrationExit = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:execute',
        'versions' => ['DoctrineMigrations\\Version20260908195213'],
        '--up' => true,
        '--no-interaction' => true,
    ]), $migrationOutput);
    check(0 === $migrationExit, 'Messenger migration failed: '.$migrationOutput->fetch());

    // 1. Establish a persistent QoS 1 subscription, then go offline.
    $repository = new MemoryRepository();
    $subscriber = new MqttClient('mqtt', 1883, CLIENT_ID, MqttClient::MQTT_3_1, $repository);
    $subscriber->connect(new ConnectionSettings(), false);
    $subscriber->subscribe(TOPIC, static function (): void {
    }, MqttClient::QOS_AT_LEAST_ONCE);
    pollUntil(static function () use ($subscriber, $repository): bool {
        $subscriber->loopOnce(microtime(true), false);

        return 1 === $repository->countSubscriptions();
    }, 10, 'Subscription was not acknowledged by the broker in time.');
    $subscriber->disconnect();

    // 2. Publish uniquely identifiable QoS 1 uplinks while the session is offline.
    $publisherRepository = new MemoryRepository();
    $publisher = new MqttClient('mqtt', 1883, CLIENT_ID.'-publisher', MqttClient::MQTT_3_1, $publisherRepository);
    $publisher->connect(new ConnectionSettings(), true);
    $markers = [];
    for ($i = 0; $i < MESSAGE_COUNT; ++$i) {
        $markers[] = $marker = 'resume-marker-'.$i;
        $publisher->publish(TOPIC, json_encode([
            'deviceInfo' => ['devEui' => $marker, 'deviceName' => 'Resume sensor'],
            'object' => ['BatV' => 3.3],
            'time' => '2020-01-01T12:00:00Z',
        ], JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE);
    }
    pollUntil(static function () use ($publisher, $publisherRepository): bool {
        $publisher->loopOnce(microtime(true), false);

        return 0 === $publisherRepository->countPendingOutgoingMessages();
    }, 10, 'Published uplinks were not acknowledged by the broker in time.');
    $publisher->disconnect();

    // 3. Start a fresh worker process with the same client ID. No Messenger
    // consumer runs, so the async queue can be inspected.
    $logFile = '/tmp/gardenhub-session-resume/worker.log';
    $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['redirect', 1],
    ], $pipes);
    check(is_resource($process), 'Could not start worker process.');

    // 4. Poll the async queue until every uplink has been enqueued at least
    // once (duplicates are tolerated; deduplication is out of scope).
    try {
        $missing = $markers;
        $deadline = microtime(true) + 30;
        while ([] !== $missing) {
            if (microtime(true) >= $deadline) {
                $workerLog = is_file($logFile) ? (string) file_get_contents($logFile) : '';
                throw new RuntimeException('Offline uplinks missing from the async queue: '.implode(',', $missing).' Worker log: '.$workerLog);
            }
            $bodies = $connection->fetchFirstColumn("SELECT body FROM messenger_messages WHERE queue_name = 'async'");
            $missing = array_values(array_filter($markers, static function (string $marker) use ($bodies): bool {
                foreach ($bodies as $body) {
                    if (str_contains((string) $body, $marker)) {
                        return false;
                    }
                }

                return true;
            }));
            usleep(200000);
        }
    } finally {
        proc_terminate($process, 9);
        proc_close($process);
    }

    // 5. Clean up the persistent broker session.
    $purge = new MqttClient('mqtt', 1883, CLIENT_ID);
    $purge->connect(new ConnectionSettings(), true);
    $purge->disconnect();

    echo 'PASS session-resume: all '.MESSAGE_COUNT." offline QoS 1 uplinks reached the async queue\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL session-resume: '.$e->getMessage()."\n");
    exit(1);
}
