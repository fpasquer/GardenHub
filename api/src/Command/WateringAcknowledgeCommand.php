<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\WateringManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:acknowledge-stop', description: 'Manually review a blocked development watering cycle')]
final class WateringAcknowledgeCommand extends Command
{
    public function __construct(private readonly WateringManager $watering)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm-actuator-off', null, InputOption::VALUE_NONE, 'Confirm independently that the watering actuator is OFF');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm-actuator-off')) {
            $output->writeln('<error>Verify the watering actuator is OFF, then pass --confirm-actuator-off.</error>');
            return Command::FAILURE;
        }
        try {
            $this->watering->acknowledgeStopped();
            $output->writeln('Blocked cycle marked reviewed. Cooldown and daily limits still apply.');
            return Command::SUCCESS;
        } catch (\DomainException|\LogicException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
