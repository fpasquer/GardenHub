<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\WateringConfiguration;
use App\Watering\WateringReadiness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:check', description: 'Read-only watering deployment or monitor readiness check')]
final class WateringCheckCommand extends Command
{
    public function __construct(
        private readonly WateringReadiness $readiness,
        private readonly WateringConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('monitor', null, InputOption::VALUE_NONE, 'Require a fresh SUBACK-gated monitor heartbeat');
        $this->addOption('allow-uninitialized', null, InputOption::VALUE_NONE, 'Allow first migration when watering is disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('monitor')) {
                $this->configuration->assertAvailable();
                $this->readiness->assertMonitorReady();
            } else {
                $this->readiness->assertIdle(
                    !$this->configuration->enabled && $input->getOption('allow-uninitialized'),
                );
            }
        } catch (\Throwable) {
            $output->writeln('<error>Watering readiness failed. Keep the monitor running; inspect configuration and unresolved cycles.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Watering readiness OK (read-only).');
        return Command::SUCCESS;
    }
}