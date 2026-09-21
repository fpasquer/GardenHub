<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Monolog\Telegram\StreamTelegramTransport;

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

function findFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (false === $socket) {
        throw new \RuntimeException("Could not reserve a free port: {$errstr}");
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function waitForServerReady(int $port, int $maxAttempts = 40): bool
{
    for ($i = 0; $i < $maxAttempts; ++$i) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if (false !== $connection) {
            fclose($connection);

            return true;
        }
        usleep(50_000);
    }

    return false;
}

/**
 * @return array{0: resource, 1: int}
 */
function startFakeServer(): array
{
    $port = findFreePort();
    $router = __DIR__.'/Support/telegram_fake_server.php';
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open(['php', '-S', "127.0.0.1:{$port}", $router], $descriptors, $pipes);

    if (false === $process || !waitForServerReady($port)) {
        throw new \RuntimeException('Fake Telegram server did not start.');
    }

    return [$process, $port];
}

function stopFakeServer($process): void
{
    proc_terminate($process);
    proc_close($process);
}

function peekCurrentHandler(): mixed
{
    $current = set_error_handler(static fn (): bool => true);
    restore_error_handler();

    return $current;
}

function test_success(string $baseUrl): void
{
    echo "Scenario: successful delivery\n";
    $transport = new StreamTelegramTransport($baseUrl);

    try {
        $transport->send('test-token', 'ok', 'hello');
        check(true, 'send() returns normally on a successful response');
    } catch (\Throwable $e) {
        check(false, 'send() returns normally on a successful response ('.$e->getMessage().')');
    }
}

function test_http_error(string $baseUrl): void
{
    echo "Scenario: HTTP error status\n";
    $transport = new StreamTelegramTransport($baseUrl);

    try {
        $transport->send('test-token', 'http_error', 'hello');
        check(false, 'send() throws on a non-2xx HTTP status');
    } catch (\RuntimeException $e) {
        check(true, 'send() throws on a non-2xx HTTP status');
        check(!str_contains($e->getMessage(), 'test-token'), 'exception message does not leak the bot token');
    }
}

function test_invalid_json(string $baseUrl): void
{
    echo "Scenario: invalid JSON body\n";
    $transport = new StreamTelegramTransport($baseUrl);

    try {
        $transport->send('test-token', 'invalid_json', 'hello');
        check(false, 'send() throws on an invalid JSON body');
    } catch (\RuntimeException) {
        check(true, 'send() throws on an invalid JSON body');
    }
}

function test_not_ok(string $baseUrl): void
{
    echo "Scenario: ok:false response\n";
    $transport = new StreamTelegramTransport($baseUrl);

    try {
        $transport->send('test-token', 'not_ok', 'hello');
        check(false, 'send() throws when the API reports ok:false');
    } catch (\RuntimeException) {
        check(true, 'send() throws when the API reports ok:false');
    }
}

function test_connection_failure_suppresses_warning(): void
{
    echo "Scenario: connection failure suppresses the warning\n";
    $deadPort = findFreePort();
    $transport = new StreamTelegramTransport("http://127.0.0.1:{$deadPort}");

    $spyCalls = 0;
    set_error_handler(static function () use (&$spyCalls): bool {
        ++$spyCalls;

        return true;
    });

    try {
        try {
            $transport->send('secret-token', 'chat', 'hello');
            check(false, 'send() throws on a connection failure');
        } catch (\RuntimeException $e) {
            check(true, 'send() throws on a connection failure');
            check(!str_contains($e->getMessage(), 'secret-token'), 'exception message does not leak the bot token');
        }
        check(0 === $spyCalls, "PHP's own warning never reaches an externally registered handler");
    } finally {
        restore_error_handler();
    }
}

function test_repeated_calls_restore_stack(string $baseUrl): void
{
    echo "Scenario: repeated calls do not leak the error-handler stack\n";
    $baseline = peekCurrentHandler();
    $marker = static fn (): bool => true;
    set_error_handler($marker);

    $transport = new StreamTelegramTransport($baseUrl);
    foreach (['ok', 'http_error', 'not_ok'] as $chatId) {
        try {
            $transport->send('test-token', $chatId, 'hello');
        } catch (\RuntimeException) {
            // Expected for the failing scenarios; only the handler stack matters here.
        }
    }

    restore_error_handler();
    check($baseline === peekCurrentHandler(), 'exactly one restore_error_handler() call unwinds back to the pre-existing handler after 3 repeated sends');
}

[$process, $port] = startFakeServer();
$baseUrl = "http://127.0.0.1:{$port}";

try {
    test_success($baseUrl);
    test_http_error($baseUrl);
    test_invalid_json($baseUrl);
    test_not_ok($baseUrl);
    test_connection_failure_suppresses_warning();
    test_repeated_calls_restore_stack($baseUrl);
} finally {
    stopFakeServer($process);
}

echo "\n";
if ($failures > 0) {
    echo "{$failures} assertion(s) FAILED.\n";
    exit(1);
}

echo "All assertions passed.\n";
exit(0);
