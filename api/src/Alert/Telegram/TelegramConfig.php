<?php

namespace App\Alert\Telegram;

use App\Alert\Telegram\Exception\TelegramConfigurationException;

/**
 * Telegram delivery configuration, resolved from environment variables in
 * services.yaml. Disabled by default; enabling without a token/chat id is
 * a configuration error caught eagerly (fail fast, no secret leakage).
 */
final readonly class TelegramConfig
{
    public function __construct(
        public bool $enabled,
        public string $botToken,
        public string $chatId,
        public string $environmentLabel,
        public float $httpTimeout,
        public float $httpMaxDuration,
    ) {
        if ($this->enabled && ('' === $this->botToken || '' === $this->chatId)) {
            throw new TelegramConfigurationException(
                'TELEGRAM_ALERTS_ENABLED is true but TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID is empty.'
            );
        }
    }
}
