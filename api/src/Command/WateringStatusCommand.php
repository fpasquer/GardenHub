<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WateringRun;
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
        foreach ($this->describe($run) as $key => $value) {
            $output->writeln(sprintf('%s: %s', $key, $value ?? '(none)'));
        }
        return Command::SUCCESS;
    }

    /** @return array<string, int|string|null> */
    private function describe(WateringRun $run): array
    {
        return [
            'id' => $run->getId(),
            'requested_seconds' => $run->getRequestedSeconds(),
            'status' => $run->getStatus(),
            'requested_at' => $this->format($run->getRequestedAt()),
            'deadline_at' => $this->format($run->getDeadlineAt()),
            'started_at' => $this->format($run->getStartedAt()),
            'finished_at' => $this->format($run->getFinishedAt()),
            'last_state_at' => $this->format($run->getLastStateAt()),
            'last_state' => $run->getLastState(),
            'error' => $run->getError(),
        ];
    }

    private function format(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s');
    }
}
