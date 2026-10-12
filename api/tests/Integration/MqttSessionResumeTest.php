<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AsyncMqttKernel;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\TemporaryWorkspace;
use Doctrine\DBAL\Connection;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

#[Group('functional_gardenhub-mqtt-session-resume')]
final class MqttSessionResumeTest extends TestCase
{
    private const CLIENT = 'tests-session-resume';
    private const TOPIC = 'tests/session-resume/up';
    private TemporaryWorkspace $workspace;
    private AsyncMqttKernel $kernel;
    private Connection $connection;
    private ?Process $worker = null;
    private string $workerLog = '';

    protected function setUp(): void
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new AsyncMqttKernel($this->workspace->path);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($manager, 'worker_lifecycle');
        $this->connection = $manager->getConnection();
    }

    protected function tearDown(): void
    {
        $this->worker?->stop(0, 9);
        $this->purgeSession();
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function testFreshWorkerQueuesAllFiveOfflineQosOneUplinks(): void
    {
        $this->purgeSession();
        $this->migrateQueue();
        $this->subscribeThenDisconnect();
        $markers = $this->publishOffline();
        $this->startWorker();
        $this->until(function () use ($markers): bool {
            self::assertTrue($this->worker->isRunning(), $this->workerLog);
            $this->worker->checkTimeout();
            return [] === $this->missing($markers);
        }, 30, 'Offline uplinks missing from async queue.');
        $bodies = implode("\n", $this->connection->fetchFirstColumn(
            "SELECT body FROM messenger_messages WHERE queue_name = 'async'",
        ));
        foreach ($markers as $marker) {
            self::assertStringContainsString($marker, $bodies);
        }
    }

    private function migrateQueue(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $this->connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:execute',
            'versions' => ['DoctrineMigrations\\Version20260908195213'],
            '--up' => true, '--no-interaction' => true,
        ]), $output);
        self::assertSame(0, $status, $output->fetch());
    }

    private function subscribeThenDisconnect(): void
    {
        $repository = new MemoryRepository();
        $subscriber = new MqttClient('mqtt', 1883, self::CLIENT, MqttClient::MQTT_3_1, $repository);
        $subscriber->connect(new ConnectionSettings(), false);
        try {
            $subscriber->subscribe(self::TOPIC, static function (): void {}, 1);
            $this->until(static function () use ($subscriber, $repository): bool {
                $subscriber->loopOnce(microtime(true), false);
                return 1 === $repository->countSubscriptions();
            }, 10, 'Broker did not acknowledge subscription.');
        } finally {
            $subscriber->disconnect();
        }
    }

    private function publishOffline(): array
    {
        $repository = new MemoryRepository();
        $publisher = new MqttClient('mqtt', 1883, self::CLIENT.'-publisher', MqttClient::MQTT_3_1, $repository);
        $publisher->connect(new ConnectionSettings(), true);
        $markers = [];
        try {
            for ($index = 0; $index < 5; ++$index) {
                $markers[] = $marker = sprintf('resume-marker-%d', $index);
                $publisher->publish(self::TOPIC, json_encode([
                    'deviceInfo' => ['devEui' => $marker, 'deviceName' => 'Resume sensor'],
                    'object' => ['BatV' => 3.3], 'time' => '2020-01-01T12:00:00Z',
                    'deduplicationId' => (string) Uuid::v4(),
                ], JSON_THROW_ON_ERROR), 1);
            }
            $this->until(static function () use ($publisher, $repository): bool {
                $publisher->loopOnce(microtime(true), false);
                return 0 === $repository->countPendingOutgoingMessages();
            }, 10, 'Broker did not acknowledge published uplinks.');
        } finally {
            $publisher->disconnect();
        }

        return $markers;
    }

    private function startWorker(): void
    {
        $this->worker = new Process([PHP_BINARY, dirname(__DIR__).'/Support/session_resume_worker.php']);
        $this->worker->setTimeout(35);
        $this->worker->start(function (string $type, string $chunk): void {
            $this->workerLog = substr($this->workerLog.$chunk, -65536);
            $this->worker->clearOutput();
            $this->worker->clearErrorOutput();
        });
    }

    private function missing(array $markers): array
    {
        $bodies = implode("\n", $this->connection->fetchFirstColumn(
            "SELECT body FROM messenger_messages WHERE queue_name = 'async'",
        ));

        return array_values(array_filter($markers,
            static fn (string $marker): bool => !str_contains($bodies, $marker)));
    }

    private function purgeSession(): void
    {
        $client = new MqttClient('mqtt', 1883, self::CLIENT);
        $client->connect(new ConnectionSettings(), true);
        $client->disconnect();
    }

    private function until(callable $condition, float $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                self::fail($message.' '.$this->workerLog);
            }
            usleep(50000);
        }
    }
}