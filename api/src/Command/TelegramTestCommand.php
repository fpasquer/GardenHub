<?php

namespace App\Command;

use Monolog\Attribute\WithMonologChannel;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually dispatches a test log record to the "telegram" channel.
 *
 * Delivery failures are swallowed by design (see TelegramHandler), so this
 * command can only report that a record was dispatched, never delivered.
 */
#[AsCommand(
    name: 'gardenhub:telegram:test',
    description: 'Dispatch a test log record to the Telegram logging channel',
)]
#[WithMonologChannel('telegram')]
class TelegramTestCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly string $minLevel = 'warning',
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->enabled) {
            $io->warning('Telegram logging is disabled (TELEGRAM_ENABLED=false). No record was dispatched.');

            return Command::SUCCESS;
        }

        $level = Logger::toMonologLevel($this->minLevel);
        $this->logger->log($level->toPsrLogLevel(), 'GardenHub Telegram test message.');

        $io->success('Test log record dispatched to the Telegram channel. Check the target chat to confirm delivery.');

        return Command::SUCCESS;
    }
}
