<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Monolog\RecordingLogger;
use App\Tests\Support\TelegramHandlerFactory;
use Monolog\Level;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-telegram-handler')]
final class TelegramHandlerTest extends TestCase
{
    use TelegramHandlerFactory;

    public function testDisabledNeverSends(): void
    {
        $transport = new FakeTelegramTransport();
        $handler = $this->handler($transport, new RecordingLogger(), false);
        $handler->handle($this->record(Level::Error, 'should not be sent'));

        self::assertSame([], $transport->calls);
    }

    public function testThresholdFiltering(): void
    {
        $transport = new FakeTelegramTransport();
        $handler = $this->handler($transport, new RecordingLogger());
        $handler->handle($this->record(Level::Info, 'below threshold'));
        self::assertSame([], $transport->calls);

        $handler->handle($this->record(Level::Warning, 'at threshold'));
        self::assertCount(1, $transport->calls);
    }

    public function testFormattingDoesNotLeakContext(): void
    {
        $transport = new FakeTelegramTransport();
        $handler = $this->handler($transport, new RecordingLogger());
        $handler->handle($this->record(
            Level::Error, 'formatted message',
            ['secret' => 'should-not-appear'],
        ));
        $text = $transport->calls[0]['text'] ?? '';

        foreach (['GardenHub', 'test', 'ERROR', 'formatted message'] as $part) {
            self::assertStringContainsString($part, $text);
        }
        self::assertStringNotContainsString('should-not-appear', $text);
    }

    public function testUnicodeTruncation(): void
    {
        $transport = new FakeTelegramTransport();
        $handler = $this->handler($transport, new RecordingLogger());
        $handler->handle($this->record(
            Level::Error, str_repeat(mb_chr(233), 5000),
        ));
        $text = $transport->calls[0]['text'] ?? '';

        self::assertLessThanOrEqual(4096, mb_strlen($text));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function testDeliveryFailureIsIsolated(): void
    {
        $transport = new FakeTelegramTransport();
        $transport->armToThrow();
        $fallback = new RecordingLogger();
        $handler = $this->handler($transport, $fallback);
        $handler->handle($this->record(Level::Error, 'will fail to send'));

        self::assertCount(1, $fallback->records);
        self::assertStringContainsString(
            'Failed to deliver', $fallback->records[0]['message'],
        );
    }

    public function testMissingCredentialsAreReportedWithoutSending(): void
    {
        $transport = new FakeTelegramTransport();
        $fallback = new RecordingLogger();
        $handler = $this->handler($transport, $fallback, true, '', '');
        $handler->handle($this->record(Level::Error, 'no credentials'));

        self::assertSame([], $transport->calls);
        $message = $fallback->records[0]['message'] ?? '';
        self::assertStringContainsString('TELEGRAM_BOT_TOKEN', $message);
        self::assertStringContainsString('TELEGRAM_CHAT_ID', $message);
    }

    public function testFallbackLoggerFailureIsIsolated(): void
    {
        $transport = new FakeTelegramTransport();
        $transport->armToThrow();
        $fallback = new RecordingLogger();
        $fallback->armToThrow();
        $handler = $this->handler($transport, $fallback);

        self::assertFalse($handler->handle(
            $this->record(Level::Error, 'double failure'),
        ));
    }
}