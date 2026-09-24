<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

use App\Monolog\Telegram\InterfaceTelegramTransport;

/**
 * In-memory Telegram transport test double for the mqtt-lifecycle suite.
 * Records calls, never performs real I/O.
 */
final class FakeTelegramTransport implements InterfaceTelegramTransport
{
    /** @var array<int, array{botToken: string, chatId: string, text: string, parseMode: ?string}> */
    public array $calls = [];

    public function send(string $botToken, string $chatId, string $text, ?string $parseMode = null): void
    {
        $this->calls[] = ['botToken' => $botToken, 'chatId' => $chatId, 'text' => $text, 'parseMode' => $parseMode];
    }
}
