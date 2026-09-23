<?php

namespace App\Command;

use App\Monolog\Telegram\InterfaceTelegramTransport;
use App\Repository\MeasurementRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Sends a compact rolling min/max measurement summary to Telegram.
 */
#[AsCommand(
    name: 'gardenhub:telegram:daily-summary',
    description: 'Send a compact measurement summary to Telegram for the last N hours',
)]
#[AsCronTask(
    expression: '0 22 * * *',
    timezone: 'UTC',
    arguments: '--hours=24',
)]
class TelegramDailySummaryCommand extends Command
{
    public function __construct(
        private readonly MeasurementRepository $measurementRepository,
        private readonly InterfaceTelegramTransport $transport,
        private readonly ClockInterface $clock,
        private readonly bool $enabled,
        private readonly string $botToken,
        private readonly string $chatId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Size of the rolling window in hours', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = $this->parseHours((string) $input->getOption('hours'), $io);

        if (null === $hours) {
            return Command::INVALID;
        }

        if (!$this->enabled) {
            $io->warning('Telegram logging is disabled (TELEGRAM_ENABLED=false). No summary was sent.');

            return Command::SUCCESS;
        }

        // Half-open window: "until" itself is excluded to avoid double-counting on consecutive runs.
        $until = $this->clock->now();
        $since = $until->modify("-{$hours} hours");

        $eventCount = $this->measurementRepository->countDistinctEventsInWindow($since, $until);
        $rows = $this->measurementRepository->aggregateMinMaxInWindow($since, $until);
        $message = $this->buildMessage($hours, $eventCount, $rows);

        try {
            $this->transport->send($this->botToken, $this->chatId, $message, 'HTML');
        } catch (\RuntimeException $e) {
            $io->error('Failed to send the Telegram summary: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Daily summary sent to Telegram.');

        return Command::SUCCESS;
    }

    /**
     * Rejects the raw option string before any int cast: a cast-first check
     * would silently truncate malformed input like "12abc" or "1.5".
     */
    private function parseHours(string $raw, SymfonyStyle $io): ?int
    {
        $hours = filter_var($raw, FILTER_VALIDATE_INT);

        if (false === $hours || $hours <= 0) {
            $io->error('The "--hours" option must be a positive integer.');

            return null;
        }

        return $hours;
    }

    /**
     * @param array<int, array{deviceName: string, sensorId: int, sensorType: string, sensorLabel: ?string, unit: string, minValue: float, maxValue: float}> $rows
     */
    private function buildMessage(int $hours, int $eventCount, array $rows): string
    {
        $header = sprintf('🌱 %dh · %d events', $hours, $eventCount);

        if (0 === $eventCount) {
            return $header;
        }

        $table = htmlspecialchars($this->buildTable($rows), ENT_QUOTES, 'UTF-8');

        return $header."\n\n<pre>{$table}</pre>";
    }

    /**
     * Builds the plain-text, padded table BEFORE any HTML escaping happens.
     *
     * @param array<int, array{deviceName: string, sensorId: int, sensorType: string, sensorLabel: ?string, unit: string, minValue: float, maxValue: float}> $rows
     */
    private function buildTable(array $rows): string
    {
        $byDevice = [];
        foreach ($rows as $row) {
            $byDevice[$row['deviceName']][] = $row;
        }

        $labelWidth = max(array_map(
            static fn (array $row): int => mb_strlen($row['sensorLabel'] ?? $row['sensorType']),
            $rows,
        ));

        $lines = [];
        foreach ($byDevice as $deviceName => $sensors) {
            $lines[] = $deviceName;
            foreach ($sensors as $row) {
                $label = $row['sensorLabel'] ?? $row['sensorType'];
                $lines[] = sprintf(
                    '%s %s → %s %s',
                    $this->pad($label, $labelWidth),
                    $this->formatValue((float) $row['minValue']),
                    $this->formatValue((float) $row['maxValue']),
                    $row['unit'],
                );
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function pad(string $text, int $width): string
    {
        $missing = $width - mb_strlen($text);

        return $missing > 0 ? $text.str_repeat(' ', $missing) : $text;
    }

    private function formatValue(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
