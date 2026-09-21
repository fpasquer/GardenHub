<?php

namespace App\Monolog\Handler;

use App\Monolog\Telegram\InterfaceTelegramTransport;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/**
 * Best-effort delivery of the "telegram" Monolog channel to a Telegram chat.
 *
 * Never throws: any failure (missing config, network, API error) is reported
 * to $fallbackLogger instead, so Telegram issues never break the caller.
 */
class TelegramHandler extends AbstractProcessingHandler
{
    private const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly InterfaceTelegramTransport $transport,
        private readonly bool $enabled,
        private readonly string $botToken,
        private readonly string $chatId,
        private readonly string $appName,
        private readonly string $environment,
        private readonly LoggerInterface $fallbackLogger,
        string $minLevel = 'warning',
    ) {
        try {
            $level = Logger::toMonologLevel($minLevel);
        } catch (\Throwable) {
            $level = Level::Warning;
        }

        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $this->doWrite($record);
        } catch (\Throwable) {
            // Absolute last resort: nothing must ever escape write().
        }
    }

    private function doWrite(LogRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }

        if ('' === $this->botToken || '' === $this->chatId) {
            $missing = implode(' and ', array_filter([
                '' === $this->botToken ? 'TELEGRAM_BOT_TOKEN' : null,
                '' === $this->chatId ? 'TELEGRAM_CHAT_ID' : null,
            ]));
            $this->safeFallbackLog(sprintf('Telegram logging is enabled but %s is not configured.', $missing));

            return;
        }

        try {
            $this->transport->send($this->botToken, $this->chatId, $this->formatMessage($record));
        } catch (\Throwable $e) {
            $this->safeFallbackLog('Failed to deliver log record to Telegram: '.$e->getMessage());
        }
    }

    private function safeFallbackLog(string $message): void
    {
        try {
            $this->fallbackLogger->error($message);
        } catch (\Throwable) {
            // The fallback sink itself must never be able to break the caller either.
        }
    }

    private function formatMessage(LogRecord $record): string
    {
        $text = sprintf(
            "[%s] %s (%s)\n%s\n%s",
            $this->appName,
            $this->environment,
            $record->level->getName(),
            $record->datetime->format('Y-m-d H:i:s'),
            $record->message,
        );

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $marker = "\n… (truncated)";
            $text = mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - mb_strlen($marker)).$marker;
        }

        return $text;
    }
}
