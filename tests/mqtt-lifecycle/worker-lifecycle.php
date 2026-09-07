<?php

declare(strict_types=1);

use App\Command\MqttConsumeCommand;
use App\Entity\Measurement;
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\TraceableMessageBus;

require '/app/vendor/autoload.php';

final class LifecycleKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-lifecycle/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-lifecycle/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach (['messenger.default_bus', 'services_resetter', 'doctrine.debug_data_holder'] as $service) {
            $container->setAlias('test.'.$service, $service)->setPublic(true);
        }
    }
}

final class CallbackLogger extends AbstractLogger
{
    public function __construct(private readonly Closure $callback)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        ($this->callback)($level, (string) $message, $context);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uplink(array $payload, string $time = '2020-01-01T12:00:00Z', ?string $name = 'Lifecycle sensor'): string
{
    return json_encode([
        'deviceInfo' => ['devEui' => 'lifecycle-device', 'deviceName' => $name],
        'object' => $payload,
        'time' => $time,
    ], JSON_THROW_ON_ERROR);
}

function runChild(string $scenario): void
{
    $process = proc_open([PHP_BINARY, __FILE__, $scenario], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['redirect', 1],
    ], $pipes);
    check(is_resource($process), 'Could not start worker test process.');
    $output = '';
    $deadline = time() + 30;
    while (!feof($pipes[1])) {
        $read = [$pipes[1]];
        $write = $except = null;
        if (time() >= $deadline || 0 === stream_select($read, $write, $except, max(1, $deadline - time()))) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            proc_close($process);
            throw new RuntimeException($scenario.': worker failed to stop within 30s. '.$output);
        }
        $output .= fread($pipes[1], 8192);
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    check(Command::FAILURE === $exitCode && str_contains($output, 'PASS '.$scenario), $scenario.': '.$output);
    echo $output;
}

function workerScenario(LifecycleKernel $kernel, string $scenario): int
{
    $container = $kernel->getContainer();
    $entityManager = $container->get('doctrine')->getManager();
    $bus = $container->get('test.messenger.default_bus');
    $resetter = $container->get('test.services_resetter');
    $debugData = $container->get('test.doctrine.debug_data_holder');
    check($entityManager->isOpen(), 'A fresh process must start with an open entity manager.');

    if ('mqtt-retry' === $scenario) {
        $connectionFailures = 0;
        $stop = new LogicException('End MQTT retry test.');
        $logger = new CallbackLogger(function (string $level, string $message) use (&$connectionFailures, $stop): void {
            check('error' === $level && str_contains($message, 'MQTT connection failed'), 'Connection failures must use MQTT recovery logs.');
            if (2 === ++$connectionFailures) {
                throw $stop;
            }
        });
        $command = new MqttConsumeCommand($bus, $logger, 'mqtt', 1, '', '', 'connection-test', 'tests/lifecycle', $resetter);
        try {
            $command->run(new ArrayInput([]), new NullOutput());
            throw new RuntimeException('MQTT connection errors must retry instead of returning.');
        } catch (LogicException $exception) {
            check($stop === $exception && 2 === $connectionFailures, 'Expected two MQTT connection attempts.');
        }
        echo "PASS mqtt-retry: connection failures reconnect instead of using the processing-failure path\n";

        return Command::FAILURE;
    }

    $messages = [];
    if ('initial' === $scenario) {
        $messages = ['{', '{"object":{"BatV":3}}', '{"deviceInfo":{"devEui":"ignored"}}'];
        for ($index = 0; $index < 20; ++$index) {
            $messages[] = uplink([
                'BatV' => 3.2,
                'temp_SOIL' => '18.5',
                'water_SOIL' => 'not numeric',
                'unmapped' => 10,
            ], name: 0 === $index ? null : 'Lifecycle sensor');
        }
        $messages[] = uplink(['water_SOIL' => 42], '2999-01-01T12:00:00Z');
        $messages[] = uplink(['water_SOIL' => 43]);
    } else {
        $messages[] = uplink(['BatV' => 3.3]);
    }
    if ('reset-failure' !== $scenario) {
        $messages[] = uplink(['BatV' => 9999]);
    }
    $expectedCallbacks = count($messages);
    $messages[] = uplink(['BatV' => 777]);

    $publisher = new MqttClient('mqtt', 1883, 'publisher-'.$scenario);
    $publisher->connect(new ConnectionSettings(), true);
    $topic = 'tests/lifecycle/'.$scenario;
    $publisher->publish($topic, array_shift($messages), MqttClient::QOS_AT_MOST_ONCE, true);

    $closedOnFlushFailure = false;
    $successfulDispatches = 0;
    $observedBus = new class($bus, $entityManager, $closedOnFlushFailure, $successfulDispatches) implements MessageBusInterface {
        public function __construct(
            private readonly MessageBusInterface $bus,
            private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
            private bool &$closedOnFlushFailure,
            private int &$successfulDispatches,
        ) {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            try {
                $envelope = $this->bus->dispatch($message, $stamps);
            } catch (Throwable $exception) {
                $this->closedOnFlushFailure = !$this->entityManager->isOpen();
                throw $exception;
            }
            ++$this->successfulDispatches;
            check(count($this->entityManager->getUnitOfWork()->getIdentityMap()[Measurement::class] ?? []) <= 2, 'Managed measurements accumulated between uplinks.');

            return $envelope;
        }
    };

    $resetCount = 0;
    $sawQueries = false;
    $cleanupError = null;
    $observedResetter = new class(function () use ($resetter, $entityManager, $bus, $debugData, $publisher, $topic, $scenario, &$messages, &$resetCount, &$sawQueries, &$cleanupError): void {
        ++$resetCount;
        $sawQueries = $sawQueries || [] !== $debugData->getData();
        $resetter->reset();
        try {
            $unitOfWork = $entityManager->getUnitOfWork();
            check(0 === $unitOfWork->size(), 'Identity map not empty after message reset.');
            check([] === $unitOfWork->getScheduledEntityInsertions(), 'Pending insertions leaked.');
            check([] === $unitOfWork->getScheduledEntityUpdates(), 'Pending updates leaked.');
            check([] === $unitOfWork->getScheduledEntityDeletions(), 'Pending deletions leaked.');
            check([] === $debugData->getData(), 'Doctrine SQL/backtrace data was retained.');
            if ($bus instanceof TraceableMessageBus) {
                check([] === $bus->getDispatchedMessages(), 'Messenger debug envelopes were retained.');
            }
        } catch (Throwable $exception) {
            $cleanupError = $exception;
            throw $exception;
        }
        if ('reset-failure' === $scenario) {
            throw new RuntimeException('Injected service reset failure.');
        }
        if ([] !== $messages) {
            $publisher->publish($topic, array_shift($messages));
        }
    }) implements ServicesResetterInterface {
        public function __construct(private readonly Closure $callback)
        {
        }

        public function reset(): void
        {
            ($this->callback)();
        }
    };

    $logs = [];
    $logger = new CallbackLogger(function (string $level, string $message) use (&$logs): void {
        $logs[] = [$level, $message];
    });
    $command = new MqttConsumeCommand($observedBus, $logger, 'mqtt', 1883, '', '', 'consumer-'.$scenario, $topic, $observedResetter);
    $status = $command->run(new ArrayInput([]), new NullOutput());
    $publisher->publish($topic, '', MqttClient::QOS_AT_MOST_ONCE, true);
    $publisher->disconnect();

    check(null === $cleanupError, $cleanupError?->getMessage() ?? 'Unexpected cleanup failure.');
    check(Command::FAILURE === $status, 'Processing failure must return a nonzero exit code.');
    check($expectedCallbacks === $resetCount, 'Every callback must reset exactly once, then stop on failure.');
    check($sawQueries, 'SQL profiling must be active before asserting its reset.');
    check(('reset-failure' === $scenario) || $closedOnFlushFailure, 'The injected flush failure must close Doctrine.');
    check(('initial' === $scenario ? 22 : 1) === $successfulDispatches, 'Unexpected number of handled uplinks.');
    check(1 === count(array_filter($logs, static fn (array $record): bool => 'critical' === $record[0])), 'Processing failure must be logged as critical.');
    check([] === array_filter($logs, static fn (array $record): bool => str_contains($record[1], 'MQTT connection failed')), 'Persistence/reset failures must not enter MQTT reconnect recovery.');
    echo 'PASS '.$scenario.': callbacks='.$resetCount.', exit='.$status."\n";

    return $status;
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new LifecycleKernel('dev', true);
    $kernel->boot();
    if (isset($argv[1])) {
        exit(workerScenario($kernel, $argv[1]));
    }

    $entityManager = $kernel->getContainer()->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    check('worker_lifecycle' === $connection->getDatabase(), 'Refusing to modify a non-test database.');
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schema = new SchemaTool($entityManager);
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);
    $connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT lifecycle_flush_failure CHECK (value <> 9999)');

    runChild('initial');
    check(41 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement'), 'Successive uplinks must persist exactly the accepted measurements.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM device'), 'Device auto-provisioning duplicated an existing device.');
    check(3 === (int) $connection->fetchOne('SELECT COUNT(*) FROM sensor'), 'Sensor auto-provisioning duplicated an existing sensor.');
    check('Lifecycle sensor' === $connection->fetchOne('SELECT name FROM device'), 'Device placeholder name was not upgraded.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM measurement WHERE measured_at > '2021-01-01'"), 'A rejected measurement leaked into a later flush.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value IN (42, 777, 9999)'), 'Rejected, failed, or post-failure uplinks were persisted.');
    check(20 === (int) $connection->fetchOne("SELECT COUNT(*) FROM measurement m JOIN sensor s ON s.id = m.sensor_id WHERE s.type = 'soil_temperature' AND m.value = 18.5"), 'Numeric payload mapping changed.');
    check('V' === $connection->fetchOne("SELECT unit FROM sensor WHERE type = 'battery'"), 'Sensor unit mapping changed.');

    $deviceIds = $connection->fetchFirstColumn('SELECT id FROM device ORDER BY id');
    $sensorIds = $connection->fetchFirstColumn('SELECT id FROM sensor ORDER BY id');
    runChild('recovery');
    check(42 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement'), 'A fresh worker process did not recover persistence.');
    check($deviceIds === $connection->fetchFirstColumn('SELECT id FROM device ORDER BY id'), 'Recovery duplicated devices.');
    check($sensorIds === $connection->fetchFirstColumn('SELECT id FROM sensor ORDER BY id'), 'Recovery duplicated sensors.');
    runChild('reset-failure');
    check(43 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement'), 'Reset failure must stop after the current uplink.');
    runChild('mqtt-retry');
    echo "PASS persistence, validation, provisioning, debug cleanup, and fresh-process recovery\n";
    $kernel->shutdown();
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n");
    exit(2);
}