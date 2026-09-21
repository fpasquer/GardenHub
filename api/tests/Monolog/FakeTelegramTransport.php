<?php

namespace App\Tests\Monolog;

use App\Monolog\Telegram\InterfaceTelegramTransport;

/**
 * In-memory test double: records calls, never performs real I/O.
 */
class FakeTelegramTransport implements InterfaceTelegramTransport
{
    /** @var array<int, array{botToken: string, chatId: string, text: string, parseMode: ?string}> */
    public array $calls = [];

    private bool $shouldThrow = false;

    public function armToThrow(): void
    {
        $this->shouldThrow = true;
    }

    public function send(string $botToken, string $chatId, string $text, ?string $parseMode = null): void
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException('Simulated Telegram delivery failure.');
        }

        $this->calls[] = ['botToken' => $botToken, 'chatId' => $chatId, 'text' => $text, 'parseMode' => $parseMode];
    }
}
