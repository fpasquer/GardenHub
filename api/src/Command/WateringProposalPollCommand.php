<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\AdvisoryLock;
use App\Watering\ProposalBot;
use App\Watering\ProposalPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:proposals', description: 'Dev-only soil proposal evaluator and Telegram callback poller')]
final class WateringProposalPollCommand extends Command
{
    private const LOCK_NAME = 'gardenhub_watering_proposals';

    public function __construct(
        private readonly AdvisoryLock $lock,
        private readonly ProposalPolicy $policy,
        private readonly ProposalBot $bot,
        private readonly bool $enabled,
        private readonly string $environment,
        private readonly string $botToken,
        private readonly string $userId,
        private readonly string $chatId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run one evaluation and polling pass');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled || $this->environment !== 'dev' || $this->botToken === '' || !ctype_digit($this->userId) || !preg_match('/^-?[0-9]+$/D', $this->chatId)) {
            $output->writeln('<error>Dev watering proposals are disabled or interactive Telegram identity is incomplete.</error>');
            return Command::FAILURE;
        }
        // Telegram permits one getUpdates consumer per bot. The DB advisory lock
        // also protects against accidental duplicate Compose replicas.
        if (!$this->lock->acquire(self::LOCK_NAME)) {
            $output->writeln('<error>Another watering proposal poller holds the lock.</error>');
            return Command::FAILURE;
        }
        try {
            $this->policy->expire(); // Interrupted approvals remain uncertain, never retried.
            do {
                $this->bot->evaluateAndNotify();
                $this->bot->reconcileMessages();
                $this->bot->pollOnce();
                $this->bot->reconcileMessages();
            } while (!$input->getOption('once'));
        } catch (\Throwable) {
            // Exceptions may include HTTP URLs with the bot token in their chain.
            $output->writeln('<error>Proposal poller stopped; inspect database state before restart.</error>');
            return Command::FAILURE;
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
        return Command::SUCCESS;
    }
}
