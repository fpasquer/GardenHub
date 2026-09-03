<?php

namespace App\Command;

use App\Mqtt\ChirpStackUplink;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches a fake ChirpStack uplink through Symfony Messenger, following
 * the exact same path as a real MQTT message. Useful to test the ingestion
 * chain locally without connecting to the lorastack-pi broker.
 */
#[AsCommand(
    name: 'gardenhub:mqtt:simulate',
    description: 'Simulate a ChirpStack uplink (same Messenger path as real MQTT messages)',
)]
class MqttSimulateCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('devEui', InputArgument::REQUIRED, 'Device EUI, e.g. a84041a1c182b3e0')
            ->addArgument('payload', InputArgument::REQUIRED, 'Decoded payload as JSON object, e.g. \'{"hum_SOIL":"25.34"}\'')
            ->addOption('time', null, InputOption::VALUE_REQUIRED, 'Measurement time (default: now), e.g. 2026-08-24T12:00:00+00:00')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'ChirpStack device name (deviceInfo.deviceName), used to name auto-provisioned devices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $devEui = (string) $input->getArgument('devEui');

        $payload = json_decode((string) $input->getArgument('payload'), true);
        if (!is_array($payload) || [] === $payload) {
            $output->writeln('<error>The payload must be a non-empty JSON object.</error>');
            return Command::INVALID;
        }

        $time = $input->getOption('time');
        try {
            $measuredAt = null !== $time ? new \DateTimeImmutable((string) $time) : new \DateTimeImmutable();
        } catch (\Throwable) {
            $output->writeln('<error>Invalid --time value.</error>');
            return Command::INVALID;
        }

        $deviceName = $input->getOption('name');
        $deviceName = is_string($deviceName) && '' !== $deviceName ? $deviceName : null;

        $this->messageBus->dispatch(new ChirpStackUplink($devEui, $payload, $measuredAt, $deviceName));

        $output->writeln(sprintf('<info>Uplink dispatched for device "%s".</info>', $devEui));

        return Command::SUCCESS;
    }
}
