<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Monolog\Handler\TelegramHandler;
use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Monolog\RecordingLogger;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Dotenv\Dotenv;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        ++$failures;
    }
}

function makeRecord(Level $level, string $message, array $context = []): LogRecord
{
    return new LogRecord(new \DateTimeImmutable(), 'telegram', $level, $message, $context);
}

function makeHandler(FakeTelegramTransport $transport, RecordingLogger $fallback, bool $enabled, string $token = 'test-token', string $chatId = 'test-chat'): TelegramHandler
{
    return new TelegramHandler($transport, $enabled, $token, $chatId, 'GardenHub', 'test', $fallback, 'warning');
}

function test_disabled_never_sends(): void
{
    echo "Scenario: disabled\n";
    $transport = new FakeTelegramTransport();
    $handler = makeHandler($transport, new RecordingLogger(), false);

    $handler->handle(makeRecord(Level::Error, 'should not be sent'));

    check([] === $transport->calls, 'transport is never invoked when disabled');
}

function test_threshold_filtering(): void
{
    echo "Scenario: threshold filtering\n";
    $transport = new FakeTelegramTransport();
    $handler = makeHandler($transport, new RecordingLogger(), true);

    $handler->handle(makeRecord(Level::Info, 'below threshold'));
    check([] === $transport->calls, 'record below minLevel does not reach the transport');

    $handler->handle(makeRecord(Level::Warning, 'at threshold'));
    check(1 === count($transport->calls), 'record at minLevel reaches the transport');
}

function test_formatting(): void
{
    echo "Scenario: formatting\n";
    $transport = new FakeTelegramTransport();
    $handler = makeHandler($transport, new RecordingLogger(), true);

    $handler->handle(makeRecord(Level::Error, 'formatted message', ['secret' => 'should-not-appear']));
    $text = $transport->calls[0]['text'] ?? '';

    check(str_contains($text, 'GardenHub'), 'message contains the app name');
    check(str_contains($text, 'test'), 'message contains the environment');
    check(str_contains($text, 'ERROR'), 'message contains the severity');
    check(str_contains($text, 'formatted message'), 'message contains the log message');
    check(!str_contains($text, 'should-not-appear'), 'message does not leak context data');
}

function test_truncation(): void
{
    echo "Scenario: unicode truncation\n";
    $transport = new FakeTelegramTransport();
    $handler = makeHandler($transport, new RecordingLogger(), true);

    $handler->handle(makeRecord(Level::Error, str_repeat('é', 5000)));
    $text = $transport->calls[0]['text'] ?? '';

    check(mb_strlen($text) <= 4096, 'message is truncated to at most 4096 characters');
    check(mb_check_encoding($text, 'UTF-8'), 'truncation does not break multi-byte characters');
}

function test_delivery_failure_isolation(): void
{
    echo "Scenario: delivery failure isolation\n";
    $transport = new FakeTelegramTransport();
    $transport->armToThrow();
    $fallback = new RecordingLogger();
    $handler = makeHandler($transport, $fallback, true);

    $handler->handle(makeRecord(Level::Error, 'will fail to send'));

    check(1 === count($fallback->records), 'exactly one fallback error is recorded');
    check(str_contains($fallback->records[0]['message'] ?? '', 'Failed to deliver'), 'fallback message explains the failure');
}

function test_missing_config_detection(): void
{
    echo "Scenario: missing config detection\n";
    $transport = new FakeTelegramTransport();
    $fallback = new RecordingLogger();
    $handler = makeHandler($transport, $fallback, true, '', '');

    $handler->handle(makeRecord(Level::Error, 'no credentials configured'));

    check([] === $transport->calls, 'transport is never called when config is missing');
    $message = $fallback->records[0]['message'] ?? '';
    check(str_contains($message, 'TELEGRAM_BOT_TOKEN') && str_contains($message, 'TELEGRAM_CHAT_ID'), 'fallback message names the missing variables');
}

function test_fallback_logger_failure_isolation(): void
{
    echo "Scenario: fallback logger failure isolation\n";
    $transport = new FakeTelegramTransport();
    $transport->armToThrow();
    $fallback = new RecordingLogger();
    $fallback->armToThrow();
    $handler = makeHandler($transport, $fallback, true);

    $handler->handle(makeRecord(Level::Error, 'double failure'));

    check(true, 'handle() did not throw even though transport and fallback both fail');
}

/**
 * Real TELEGRAM_* process env vars (e.g. injected by Docker Compose) must
 * never win over api/.env.test's dummy values in this scenario, otherwise
 * the "enabled" path below would silently not be exercised.
 */
function clearRealTelegramEnv(): void
{
    foreach (['TELEGRAM_ENABLED', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'TELEGRAM_MIN_LEVEL'] as $var) {
        putenv($var);
        unset($_ENV[$var], $_SERVER[$var]);
    }
}

function test_channel_isolation(): void
{
    echo "Scenario: channel isolation (container, enabled path with dummy credentials)\n";

    clearRealTelegramEnv();
    $_SERVER['APP_ENV'] = 'test';
    putenv('APP_ENV=test');
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

    $kernel = new \App\Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');

    $appLogger = $container->get('logger');
    $telegramLogger = $container->get('monolog.logger.telegram');
    $fakeTransport = $container->get(FakeTelegramTransport::class);

    $hasTelegramHandler = static fn ($logger) => (bool) array_filter(
        $logger->getHandlers(),
        static fn ($h) => $h instanceof TelegramHandler,
    );

    check(!$hasTelegramHandler($appLogger), 'the app channel logger has no TelegramHandler');
    check($hasTelegramHandler($telegramLogger), 'the telegram channel logger has a TelegramHandler');

    $appLogger->warning('app channel message');
    check([] === $fakeTransport->calls, 'logging on the app channel never reaches the (fake) transport');

    $telegramLogger->warning('telegram channel message');
    check(1 === count($fakeTransport->calls), 'logging on the telegram channel reaches the (fake) transport (delivery actually exercised, not skipped as disabled)');

    $kernel->shutdown();
}

test_disabled_never_sends();
test_threshold_filtering();
test_formatting();
test_truncation();
test_delivery_failure_isolation();
test_missing_config_detection();
test_fallback_logger_failure_isolation();
test_channel_isolation();

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) FAILED.\n";
    exit(1);
}

echo "All assertions passed.\n";
exit(0);
