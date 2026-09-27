<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\WateringManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:request', description: 'Request a short manual watering cycle on the dev simulator')]
final class WateringRequestCommand extends Command
{
    public function __construct(private readonly WateringManager $watering)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('seconds', InputArgument::REQUIRED, 'Run duration, 1–30 seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = (string) $input->getArgument('seconds');
        if (!ctype_digit($raw)) {
            $output->writeln('<error>Seconds must be a positive integer.</error>');
            return Command::INVALID;
        }
        try {
            $id = $this->watering->request((int) $raw);
            $output->writeln('Watering request reserved and publish attempted: '.$id.'. Verify ON then OFF in the monitor.');
            return Command::SUCCESS;
        } catch (\DomainException|\LogicException|\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
