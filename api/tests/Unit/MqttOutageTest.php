<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\MqttConsumeCommand;
use App\Tests\Monolog\RecordingLogger;
use App\Tests\Support\Command;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\MqttLifecycle\ScriptedMqttClientFactory;

require_once Command::root().'/tests/mqtt-lifecycle/src/ScriptedMqttClient.php';
require_once Command::root().'/tests/mqtt-lifecycle/src/ScriptedMqttClientFactory.php';

#[Group('unitest_gardenhub-mqtt-outage')]
final class MqttOutageTest extends TestCase
{
    public function testThresholdAlertsOnceAndReconnectAlertsRecovery(): void
    {
        $stop = new \LogicException('End MQTT outage test.');
        $script = array_fill(0, 13, new DataTransferException(0, 'Simulated connect failure.'));
        $script[] = static function (): void {};
        $script[] = static function (): void {
            throw new DataTransferException(0, 'Simulated mid-loop drop.');
        };
        $script[] = static function () use ($stop): void { throw $stop; };
        [$logger, $telegram] = $this->execute($script, $stop);
        foreach ($logger->records as $record) {
            $failure = 'error' === $record['level']
                && str_contains($record['message'], 'MQTT connection failed');
            $connected = 'info' === $record['level']
                && str_contains($record['message'], 'Connected to MQTT broker');
            self::assertTrue($failure || $connected, $record['message']);
        }
        $alerts = array_values(array_filter($telegram->records,
            static fn (array $record): bool => 'critical' === $record['level']));
        self::assertCount(2, $alerts);
        self::assertStringContainsString('12', $alerts[0]['message']);
        self::assertStringNotContainsString('Simulated', $alerts[0]['message']);
        self::assertStringContainsString('recovered', $alerts[1]['message']);
    }

    public function testFreshWorkerDoesNotInheritOutageState(): void
    {
        $stop = new \LogicException('End fresh MQTT instance test.');
        [, $telegram] = $this->execute([
            static function () use ($stop): void { throw $stop; },
        ], $stop);
        self::assertSame([], $telegram->records);
    }

    private function execute(array $script, \LogicException $stop): array
    {
        $logger = new RecordingLogger();
        $telegram = new RecordingLogger();
        $command = new MqttConsumeCommand(
            $this->createStub(MessageBusInterface::class), $logger,
            'mqtt', 1, '', '', 'outage-test', 'tests/outage',
            $this->createStub(ServicesResetterInterface::class),
            new ScriptedMqttClientFactory($script), $telegram, 0,
        );
        try {
            $command->run(new ArrayInput([]), new NullOutput());
            self::fail('The sentinel exception must propagate.');
        } catch (\LogicException $exception) {
            self::assertSame($stop, $exception);
        }

        return [$logger, $telegram];
    }
}