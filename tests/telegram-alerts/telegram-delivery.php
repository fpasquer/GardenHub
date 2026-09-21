<?php

declare(strict_types=1);

/*
 * Pure unit-level coverage for Telegram delivery mechanics (TelegramConfig +
 * TelegramAlertHandler): no Symfony kernel, no database. Every scenario runs
 * against a FakeTelegramHttpClient standing in for the real Telegram Bot
 * API, so nothing here ever reaches the network.
 */

use App\Alert\Telegram\Exception\TelegramConfigurationException;
use App\Alert\Telegram\Exception\TelegramDeliveryException;
use App\Alert\Telegram\TelegramAlertHandler;
use App\Alert\Telegram\TelegramConfig;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Tests\TelegramAlerts\FakeTelegramHttpClient;

require '/app/vendor/autoload.php';

// Test-only classes under /tests/src are not in the Composer autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\TelegramAlerts\\';
    if (str_starts_with($class, $prefix)) {
        require '/tests/src/'.substr($class, strlen($prefix)).'.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function record(string $message, Level $level = Level::Warning): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2020-01-01T12:00:00Z'),
        channel: 'telegram_alerts',
        level: $level,
        message: $message,
    );
}

function handler(TelegramConfig $config): TelegramAlertHandler
{
    return new TelegramAlertHandler($config, new FakeTelegramHttpClient(), new NullLogger(), Level::Warning);
}

function config(bool $enabled, string $botToken = 'test-bot-token', string $chatId = '424242'): TelegramConfig
{
    return new TelegramConfig(
        enabled: $enabled,
        botToken: $botToken,
        chatId: $chatId,
        environmentLabel: 'test',
        httpTimeout: 5.0,
        httpMaxDuration: 10.0,
    );
}

/** @return array<string, string> */
function requestBody(array $request): array
{
    parse_str((string) $request['options']['body'], $parsed);

    return $parsed;
}

// 1. Disabled: zero network calls, no exception.
FakeTelegramHttpClient::reset();
handler(config(enabled: false))->handle(record('battery critical'));
check([] === FakeTelegramHttpClient::requests(), 'A disabled handler must make zero HTTP requests.');
echo "PASS disabled: zero network calls when Telegram alerts are disabled\n";

// 2. Enabled + missing credentials: fails fast at construction.
try {
    config(enabled: true, botToken: '', chatId: '424242');
    check(false, 'Enabling without a bot token must throw eagerly.');
} catch (TelegramConfigurationException) {
    // expected
}
try {
    config(enabled: true, botToken: 'test-bot-token', chatId: '');
    check(false, 'Enabling without a chat id must throw eagerly.');
} catch (TelegramConfigurationException) {
    // expected
}
echo "PASS missing-credentials: enabling without a bot token/chat id fails eagerly with a configuration error\n";

// 3. Enabled + valid credentials: exactly one request, correct URL and body.
FakeTelegramHttpClient::reset();
handler(config(enabled: true))->handle(record('soil moisture critically low'));
$requests = FakeTelegramHttpClient::requests();
check(1 === count($requests), 'Exactly one HTTP request must be made per log record.');
check('https://api.telegram.org/bottest-bot-token/sendMessage' === $requests[0]['url'], 'The request must target the configured bot token.');
$body = requestBody($requests[0]);
check('424242' === $body['chat_id'], 'The request body must carry the configured chat id.');
check(str_contains($body['text'], '[GardenHub][TEST]'), 'The message must be prefixed with the environment label.');
check(str_contains($body['text'], 'soil moisture critically low'), 'The message must contain the original log text.');
echo "PASS successful-send: an enabled handler sends exactly one correctly-formed request\n";

// 4. Below the configured level: zero network calls (isHandling() gate).
FakeTelegramHttpClient::reset();
handler(config(enabled: true))->handle(record('routine info line', Level::Info));
check([] === FakeTelegramHttpClient::requests(), "A record below the handler's minimum level must never reach the network.");
echo "PASS below-level: records below the configured minimum level make zero network calls\n";

// 5. Long messages are truncated to Telegram's 4096-character limit.
FakeTelegramHttpClient::reset();
handler(config(enabled: true))->handle(record(str_repeat('x', 5000)));
$text = requestBody(FakeTelegramHttpClient::requests()[0])['text'];
check(4096 === mb_strlen($text), 'Truncated text must be exactly 4096 characters.');
check(str_ends_with($text, '…'), 'Truncated text must end with an ellipsis.');
echo "PASS truncation: messages longer than 4096 characters are truncated with an ellipsis\n";

// 6. Retryable failures (429, 5xx) throw TelegramDeliveryException.
foreach ([429, 500, 503] as $status) {
    FakeTelegramHttpClient::reset();
    FakeTelegramHttpClient::queueResponse(new MockResponse((string) json_encode(['ok' => false]), ['http_code' => $status]));
    try {
        handler(config(enabled: true))->handle(record('retryable failure'));
        check(false, "HTTP $status must throw TelegramDeliveryException.");
    } catch (TelegramDeliveryException) {
        // expected
    }
}
echo "PASS retryable-failures: HTTP 429/5xx responses throw TelegramDeliveryException (left to the transport's own retry policy)\n";

// 7. Permanent failures (4xx other than 429) throw UnrecoverableMessageHandlingException.
FakeTelegramHttpClient::reset();
FakeTelegramHttpClient::queueResponse(new MockResponse((string) json_encode(['ok' => false, 'description' => 'Forbidden']), ['http_code' => 403]));
try {
    handler(config(enabled: true))->handle(record('permanent failure'));
    check(false, 'HTTP 403 must throw UnrecoverableMessageHandlingException.');
} catch (UnrecoverableMessageHandlingException) {
    // expected
}
echo "PASS permanent-failure: a 4xx (non-429) response throws UnrecoverableMessageHandlingException, skipping straight to the failure transport\n";

// 8. A 200 response without ok:true is still treated as a delivery failure.
FakeTelegramHttpClient::reset();
FakeTelegramHttpClient::queueResponse(new MockResponse((string) json_encode(['ok' => false]), ['http_code' => 200]));
try {
    handler(config(enabled: true))->handle(record('malformed success'));
    check(false, 'A 200 response with ok:false must still be treated as a delivery failure.');
} catch (TelegramDeliveryException) {
    // expected
}
echo "PASS malformed-ok: a 200 response without ok:true is treated as a delivery failure\n";

// 9. A transport-level error (no response at all) is wrapped, not left uncaught.
FakeTelegramHttpClient::reset();
FakeTelegramHttpClient::queueResponse(new MockResponse('', ['error' => 'Connection refused']));
try {
    handler(config(enabled: true))->handle(record('network failure'));
    check(false, 'A transport-level error must be wrapped into TelegramDeliveryException.');
} catch (TelegramDeliveryException) {
    // expected
}
echo "PASS transport-error: a network-level failure is wrapped into TelegramDeliveryException, not left uncaught\n";
