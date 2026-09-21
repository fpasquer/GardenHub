<?php

namespace App\Monolog\Telegram;

/**
 * Sends a single message to the Telegram Bot API.
 */
interface InterfaceTelegramTransport
{
    /**
     * @param ?string $parseMode Telegram parse_mode (e.g. 'HTML'); omit for plain text
     *
     * @throws \RuntimeException on any failure. The message must never
     *                            include the bot token or request URL.
     */
    public function send(string $botToken, string $chatId, string $text, ?string $parseMode = null): void;
}
