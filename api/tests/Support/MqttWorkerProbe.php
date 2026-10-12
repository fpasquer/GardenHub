<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Command\MqttConsumeCommand;
use App\Entity\Measurement;
use App\Tests\Monolog\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Uid\Uuid;

final class MqttWorkerProbe
{
    private MqttClient $publisher;
    private array $messages;
    private int $expectedCallbacks;
    private int $resetCount = 0;
    private int $successfulDispatches = 0;
    private bool $closedOnFailure = false;
    private bool $sawQueries = false;
    private ?\Throwable $cleanupError = null;
    private readonly string $topic;
    private readonly EntityManagerInterface $manager;

    public function __construct(private readonly MqttLifecycleKernel $kernel, private readonly string $scenario)
    {
        $this->topic = 'tests/lifecycle/'.$scenario;
        $this->manager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->messages = $this->messages();
        $this->expectedCallbacks = count($this->messages);
        $this->messages[] = $this->uplink(['BatV' => 777]);
    }

    public function run(): void
    {
        $container = $this->kernel->getContainer();
        Assert::assertTrue($this->manager->isOpen());
        $this->publisher = new MqttClient('mqtt', 1883, 'publisher-'.$this->scenario);
        $this->publisher->connect(new ConnectionSettings(), true);
        try {
            $this->publisher->publish($this->topic, array_shift($this->messages), 0, true);
            $logger = new RecordingLogger();
            $command = new MqttConsumeCommand($this->observedBus(), $logger,
                'mqtt', 1883, '', '', 'consumer-'.$this->scenario,
                $this->topic, $this->observedResetter());
            $status = $command->run(new ArrayInput([]), new NullOutput());
            $this->assertResults($status, $logger);
        } finally {
            $this->publisher->publish($this->topic, '', 0, true);
            $this->publisher->disconnect();
        }
    }

    public function dispatch(object $message, array $stamps): Envelope
    {
        $manager = $this->manager;
        try {
            $envelope = $this->kernel->getContainer()->get('test.messenger.default_bus')
                ->dispatch($message, $stamps);
        } catch (\Throwable $exception) {
            $this->closedOnFailure = !$manager->isOpen();
            throw $exception;
        }
        ++$this->successfulDispatches;
        Assert::assertLessThanOrEqual(2,
            count($manager->getUnitOfWork()->getIdentityMap()[Measurement::class] ?? []));

        return $envelope;
    }

    public function reset(): void
    {
        ++$this->resetCount;
        $container = $this->kernel->getContainer();
        $debug = $container->get('test.doctrine.debug_data_holder');
        $this->sawQueries = $this->sawQueries || [] !== $debug->getData();
        $container->get('test.services_resetter')->reset();
        try {
            $unit = $this->manager->getUnitOfWork();
            Assert::assertSame(0, $unit->size());
            Assert::assertSame([], $unit->getScheduledEntityInsertions());
            Assert::assertSame([], $unit->getScheduledEntityUpdates());
            Assert::assertSame([], $unit->getScheduledEntityDeletions());
            Assert::assertSame([], $debug->getData());
            $bus = $container->get('test.messenger.default_bus');
            if ($bus instanceof TraceableMessageBus) {
                Assert::assertSame([], $bus->getDispatchedMessages());
            }
        } catch (\Throwable $exception) {
            $this->cleanupError = $exception;
            throw $exception;
        }
        if ('reset-failure' === $this->scenario) {
            throw new \RuntimeException('Injected service reset failure.');
        }
        if ([] !== $this->messages) {
            $this->publisher->publish($this->topic, array_shift($this->messages));
        }
    }

    private function assertResults(int $status, RecordingLogger $logger): void
    {
        Assert::assertNull($this->cleanupError, $this->cleanupError?->getMessage() ?? '');
        Assert::assertSame(1, $status);
        Assert::assertSame($this->expectedCallbacks, $this->resetCount);
        Assert::assertTrue($this->sawQueries);
        Assert::assertTrue('reset-failure' === $this->scenario || $this->closedOnFailure);
        Assert::assertSame('initial' === $this->scenario ? 22 : 1, $this->successfulDispatches);
        Assert::assertCount(1, array_filter($logger->records,
            static fn (array $record): bool => 'critical' === $record['level']));
        Assert::assertSame([], array_filter($logger->records,
            static fn (array $record): bool => str_contains($record['message'], 'MQTT connection failed')));
    }

    private function observedBus(): MessageBusInterface
    {
        return new class($this) implements MessageBusInterface {
            public function __construct(private readonly MqttWorkerProbe $probe) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->probe->dispatch($message, $stamps);
            }
        };
    }

    private function observedResetter(): ServicesResetterInterface
    {
        return new class($this) implements ServicesResetterInterface {
            public function __construct(private readonly MqttWorkerProbe $probe) {}
            public function reset(): void { $this->probe->reset(); }
        };
    }

    private function messages(): array
    {
        if ('initial' !== $this->scenario) {
            $messages = [$this->uplink(['BatV' => 3.3])];
        } else {
            $messages = ['{', '{"object":{"BatV":3}}', '{"deviceInfo":{"devEui":"ignored"}}'];
            for ($index = 0; $index < 20; ++$index) {
                $messages[] = $this->uplink(['BatV' => 3.2, 'temp_SOIL' => '18.5',
                    'water_SOIL' => 'not numeric', 'unmapped' => 10],
                    name: 0 === $index ? null : 'Lifecycle sensor');
            }
            $messages[] = $this->uplink(['water_SOIL' => 42], '2999-01-01T12:00:00Z');
            $messages[] = $this->uplink(['water_SOIL' => 43]);
        }
        if ('reset-failure' !== $this->scenario) {
            $messages[] = $this->uplink(['BatV' => 9999]);
        }

        return $messages;
    }

    private function uplink(array $payload, string $time = '2020-01-01T12:00:00Z', ?string $name = 'Lifecycle sensor'): string
    {
        return json_encode(['deviceInfo' => ['devEui' => 'lifecycle-device', 'deviceName' => $name],
            'object' => $payload, 'time' => $time, 'deduplicationId' => (string) Uuid::v4()], JSON_THROW_ON_ERROR);
    }
}