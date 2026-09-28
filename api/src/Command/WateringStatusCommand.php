<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\WateringManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:status', description: 'Show the last watering cycle')]
final class WateringStatusCommand extends Command
{
    public function __construct(private readonly WateringManager $watering)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $run = $this->watering->latest();
        if ($run === null) {
            $output->writeln('No watering cycle recorded.');
            return Command::SUCCESS;
        }
        foreach ($run as $key => $value) {
            $output->writeln($key.': '.($value ?? '(none)'));
        }
        return Command::SUCCESS;
    }
}
