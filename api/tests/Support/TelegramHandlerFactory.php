<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Monolog\Handler\TelegramHandler;
use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Monolog\RecordingLogger;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

trait TelegramHandlerFactory
{
    private function handler(
        FakeTelegramTransport $transport,
        RecordingLogger $fallback,
        bool $enabled = true,
        string $token = 'test-token',
        string $chatId = 'test-chat',
    ): TelegramHandler {
        return new TelegramHandler(
            $transport, $enabled, $token, $chatId,
            'GardenHub', 'test', $fallback, 'warning',
        );
    }

    private function record(
        Level $level,
        string $message,
        array $context = [],
    ): LogRecord {
        return new LogRecord(
            new DateTimeImmutable(), 'telegram', $level, $message, $context,
        );
    }
}