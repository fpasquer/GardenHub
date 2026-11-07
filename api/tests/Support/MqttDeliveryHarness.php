<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Command\MqttConsumeCommand;
use App\Tests\Monolog\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class MqttDeliveryHarness
{
    public readonly MqttDeliveryKernel $kernel;
    public readonly Connection $connection;
    private TemporaryWorkspace $workspace;
    private Application $application;

    public function __construct()
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new MqttDeliveryKernel($this->workspace->path);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($manager, 'worker_lifecycle');
        $schema = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->connection = $manager->getConnection();
        $this->connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $this->connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        $this->application = new Application($this->kernel);
        $this->application->setAutoExit(false);
        $this->console(['command' => 'doctrine:migrations:execute',
            'versions' => ['DoctrineMigrations\\Version20260908195213'],
            '--up' => true, '--no-interaction' => true]);
        $this->connection->close();
    }

    public function shutdown(): void
    {
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function ingest(string $payload): array
    {
        $container = $this->kernel->getContainer();
        $logger = new RecordingLogger();
        $bus = new class($container->get('test.messenger.default_bus')) implements MessageBusInterface {
            public ?Envelope $envelope = null;
            public function __construct(private readonly MessageBusInterface $bus) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->envelope = $this->bus->dispatch($message, $stamps);
            }
        };
        $command = new MqttConsumeCommand($bus, $logger, 'mqtt', 1883, '', '',
            'delivery-test', 'tests/delivery', $container->get('test.services_resetter'));
        (new \ReflectionMethod(MqttConsumeCommand::class, 'handleMessage'))
            ->invoke($command, 'tests/delivery/topic', $payload);

        return [$logger, $bus->envelope];
    }

    public function consume(int $limit = 1): string
    {
        $event = \Symfony\Component\Messenger\Event\WorkerRunningEvent::class;
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        foreach ($dispatcher->getListeners($event) as $listener) {
            $target = is_array($listener) ? $listener[0] : $listener;
            if ($target instanceof \Symfony\Component\EventDispatcher\Debug\WrappedListener) {
                $target = $target->getWrappedListener();
                $target = is_array($target) ? $target[0] : $target;
            }
            if ($target instanceof \Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener) {
                $dispatcher->removeListener($event, $listener);
            }
        }

        return $this->console(['command' => 'messenger:consume',
            'receivers' => ['async'], '--limit' => $limit, '--time-limit' => 30]);
    }

    public function console(array $input): string
    {
        $output = new BufferedOutput();
        $status = $this->application->run(new ArrayInput($input), $output);
        $text = $output->fetch();
        Assert::assertSame(0, $status, $text);

        return $text;
    }

    public function makeAvailable(): void
    {
        $this->connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND delivered_at IS NULL");
    }

    public static function uplink(array $payload, string $time = '2020-01-01T12:00:00Z', string $device = 'delivery-device', ?string $id = null, ?string $name = 'Delivery sensor'): string
    {
        return json_encode(['deviceInfo' => ['devEui' => $device, 'deviceName' => $name],
            'object' => $payload, 'time' => $time, 'deduplicationId' => $id ?? (string) Uuid::v4()], JSON_THROW_ON_ERROR);
    }
}