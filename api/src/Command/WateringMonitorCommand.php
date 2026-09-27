<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\WateringMonitorRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:monitor', description: 'Track dev watering state and enforce its deadline')]
final class WateringMonitorCommand extends Command
{
    public function __construct(
        private readonly WateringMonitorRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled || $this->environment !== 'dev' || !$this->runner->isConfigured()) {
            $output->writeln('<error>Development watering monitor is disabled or not configured.</error>');
            return Command::FAILURE;
        }

        while (true) {
            try {
                $this->runner->runConnectionAttempt(static fn (): bool => false);
            } catch (\Throwable $e) {
                $this->logger->error('Watering monitor connection or state failure; retrying.', ['error' => $e->getMessage()]);
                $output->writeln('<error>Watering monitor retry: '.$e->getMessage().'</error>');
                sleep(2);
            }
        }
    }
}
