<?php

declare(strict_types=1);

/*
 * Deterministic regression test: a QoS 1 PUBLISH that arrives before the
 * subscription's SUBACK must still reach the application callback.
 *
 * A minimal broker stub (child process) forces the packet order on the very
 * connection used by the client under test:
 *
 *   CONNECT -> CONNACK -> SUBSCRIBE -> PUBLISH A -> SUBACK -> PUBLISH B -> PUBLISH C (other topic)
 *
 * Assertions (client side):
 *  - marker A (pre-SUBACK) is delivered exactly once
 *  - marker B (post-SUBACK) is delivered exactly once, i.e. the SUBACK-time
 *    replacement of the pre-registered subscription causes no duplicate
 *  - marker C (unrelated topic) is never delivered
 *
 * Without the pre-registration in WorkerMqttClientFactory, marker A is
 * acknowledged by the client library but silently dropped (the subscription
 * only becomes active on SUBACK), so this test fails against the original
 * implementation and passes with the fix.
 */

use App\Mqtt\WorkerMqttClientFactory;

require '/app/vendor/autoload.php';

const STUB_PORT = 11883;
const TOPIC = 'tests/pre-suback/up';
const OTHER_TOPIC = 'tests/pre-suback/other';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// -------------------------------------------------------------------------
// Broker stub helpers
// -------------------------------------------------------------------------

/** @param resource $conn */
function readExact($conn, int $length, float $deadline): string
{
    $data = '';
    while (strlen($data) < $length) {
        check(microtime(true) < $deadline, 'stub: read timeout');
        $chunk = @fread($conn, $length - strlen($data));
        if (false === $chunk) {
            throw new RuntimeException('stub: read failed');
        }
        if ('' === $chunk) {
            check(!feof($conn), 'stub: unexpected EOF');
            usleep(1000);
            continue;
        }
        $data .= $chunk;
    }

    return $data;
}

/**
 * @param resource $conn
 *
 * @return array{int, string} fixed header byte and packet body
 */
function readPacket($conn, float $deadline): array
{
    $header = ord(readExact($conn, 1, $deadline));
    $multiplier = 1;
    $remaining = 0;
    do {
        $byte = ord(readExact($conn, 1, $deadline));
        $remaining += ($byte & 127) * $multiplier;
        $multiplier *= 128;
    } while ($byte & 128);

    return [$header, readExact($conn, $remaining, $deadline)];
}

/** @param resource $conn */
function writeAll($conn, string $data, float $deadline): void
{
    $written = 0;
    while ($written < strlen($data)) {
        check(microtime(true) < $deadline, 'stub: write timeout');
        $result = @fwrite($conn, substr($data, $written));
        if (false === $result) {
            throw new RuntimeException('stub: write failed');
        }
        $written += $result;
    }
}

function publishPacket(string $topic, string $payload, int $packetId): string
{
    $variableHeader = pack('n', strlen($topic)).$topic.pack('n', $packetId);
    $body = $variableHeader.$payload;
    check(strlen($body) < 128, 'stub: packet too large for single-byte remaining length');

    // 0x32: PUBLISH with QoS 1; remaining length fits into a single byte.
    return "\x32".chr(strlen($body)).$body;
}

/**
 * Minimal broker stub forcing PUBLISH-before-SUBACK on its only connection.
 */
function brokerStub(): int
{
    $deadline = microtime(true) + 20;
    $server = stream_socket_server('tcp://127.0.0.1:'.STUB_PORT, $errno, $error);
    check(false !== $server, 'stub: listen failed: '.$error);
    echo "READY\n";

    $conn = stream_socket_accept($server, 15);
    check(false !== $conn, 'stub: accept timeout');
    stream_set_blocking($conn, false);

    [$type] = readPacket($conn, $deadline);
    check(0x01 === $type >> 4, 'stub: expected CONNECT');
    writeAll($conn, "\x20\x02\x00\x00", $deadline); // CONNACK

    [$type, $body] = readPacket($conn, $deadline);
    check(0x08 === $type >> 4, 'stub: expected SUBSCRIBE');
    $messageId = unpack('n', substr($body, 0, 2))[1];
    $topicLength = unpack('n', substr($body, 2, 2))[1];
    $topic = substr($body, 4, $topicLength);
    check(TOPIC === $topic, 'stub: unexpected topic '.$topic);

    // The decisive ordering: the resumed-session PUBLISH is sent BEFORE the
    // SUBACK, on the same connection the client under test is using.
    writeAll($conn, publishPacket($topic, 'marker-a', 1), $deadline);
    writeAll($conn, "\x90\x03".pack('n', $messageId)."\x01", $deadline); // SUBACK, QoS 1 granted
    writeAll($conn, publishPacket($topic, 'marker-b', 2), $deadline);
    writeAll($conn, publishPacket(OTHER_TOPIC, 'marker-c', 3), $deadline);

    // Drain client PUBACKs/DISCONNECT until the client goes away.
    while (microtime(true) < $deadline && !feof($conn)) {
        @fread($conn, 8192);
        usleep(10000);
    }

    return 0;
}

// -------------------------------------------------------------------------
// Client under test
// -------------------------------------------------------------------------

function runClient(): void
{
    // Same factory the worker command uses, pointed at the stub broker.
    $factory = new WorkerMqttClientFactory('127.0.0.1', STUB_PORT, '', '');

    /** @var list<array{string, string}> $received */
    $received = [];
    $client = $factory->create('tests-pre-suback', TOPIC, function (string $topic, string $message) use (&$received): void {
        $received[] = [$topic, $message];
    });

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && count($received) < 2) {
        $client->loopOnce(microtime(true), false);
    }
    $client->disconnect();

    $deliveries = static function (string $marker) use ($received): array {
        return array_values(array_filter($received, static fn (array $record): bool => $record[1] === $marker));
    };

    $a = $deliveries('marker-a');
    check(1 === count($a) && TOPIC === $a[0][0], 'Pre-SUBACK PUBLISH must reach the callback exactly once.');
    $b = $deliveries('marker-b');
    check(1 === count($b) && TOPIC === $b[0][0], 'Post-SUBACK PUBLISH must reach the callback exactly once (no duplicate).');
    check([] === $deliveries('marker-c'), 'PUBLISH on an unrelated topic must not reach the callback.');

    echo "PASS pre-suback-delivery: pre-SUBACK delivered once, post-SUBACK delivered once, unrelated topic ignored\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');

    if (isset($argv[1]) && 'broker' === $argv[1]) {
        exit(brokerStub());
    }

    $process = proc_open([PHP_BINARY, __FILE__, 'broker'], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['redirect', 1],
    ], $pipes);
    check(is_resource($process), 'Could not start broker stub process.');

    // Wait for the stub to listen (bounded; no sleep-based synchronization).
    stream_set_blocking($pipes[1], false);
    $ready = '';
    $deadline = microtime(true) + 10;
    while (!str_contains($ready, 'READY')) {
        check(microtime(true) < $deadline, 'Broker stub did not become ready.');
        $status = proc_get_status($process);
        check($status['running'], 'Broker stub exited early: '.$ready);
        $chunk = fgets($pipes[1]);
        if (false !== $chunk) {
            $ready .= $chunk;
        } else {
            usleep(10000);
        }
    }

    try {
        runClient();
    } finally {
        $output = $ready.(string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        check(0 === $exit, 'Broker stub failed: '.$output);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL pre-suback-delivery: '.$e->getMessage()."\n");
    exit(1);
}
