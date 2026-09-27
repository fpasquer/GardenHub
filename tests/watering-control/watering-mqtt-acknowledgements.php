<?php

declare(strict_types=1);

/*
 * Regression coverage for MQTT acknowledgement handling in the dev watering
 * feature:
 *  - MqttWateringPublisher::publish() must detect a withheld PUBACK instead
 *    of treating loop()'s normal return as a successful delivery.
 *  - WateringMonitorRunner must only start the heartbeat once a real SUBACK
 *    confirms the state subscription (never on a missing or rejected one),
 *    and must invalidate it again on disconnect and re-establish it after a
 *    successful reconnect.
 *
 * Broker stubs (child processes, one per scenario) force the exact packet
 * sequence needed for the unhappy paths; the happy-path scenarios use the
 * real Mosquitto broker from this suite's Compose file.
 */

use App\Kernel;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringManager;
use App\Watering\WateringMonitorRunner;
use App\Watering\WateringPublisher;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\NullLogger;

require '/app/vendor/autoload.php';

const STUB_HOST = '127.0.0.1';
const MOSQUITTO_HOST = 'mqtt';
const MOSQUITTO_PORT = 1883;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pollUntil(callable $condition, float $seconds, string $message): void
{
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        check(microtime(true) < $deadline, $message);
        usleep(50000);
    }
}

final class FakePublisher implements WateringPublisher
{
    public array $commands = [];

    public function publish(array $command): void
    {
        $this->commands[] = $command;
    }
}

// -------------------------------------------------------------------------
// Shared low-level packet helpers (self-contained; mirrors
// tests/mqtt-lifecycle/pre-suback-delivery.php).
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

/** @return resource */
function acceptOne(int $port, float $deadline)
{
    $server = stream_socket_server('tcp://'.STUB_HOST.':'.$port, $errno, $error);
    check(false !== $server, 'stub: listen failed: '.$error);
    echo "READY\n";
    $conn = stream_socket_accept($server, 15);
    check(false !== $conn, 'stub: accept timeout');
    stream_set_blocking($conn, false);

    return $conn;
}

/** @param resource $conn */
function stubExpectConnect($conn, float $deadline): void
{
    [$type] = readPacket($conn, $deadline);
    check(0x01 === $type >> 4, 'stub: expected CONNECT');
    writeAll($conn, "\x20\x02\x00\x00", $deadline); // CONNACK
}

/** @param resource $conn */
function stubDrainUntil($conn, float $deadline): void
{
    while (microtime(true) < $deadline && !feof($conn)) {
        @fread($conn, 8192);
        usleep(10000);
    }
}

// -------------------------------------------------------------------------
// Broker stub modes, run in a child process via `php this-file.php broker ...`.
// -------------------------------------------------------------------------

function stubWithholdPuback(int $port): int
{
    $deadline = microtime(true) + 20;
    $conn = acceptOne($port, $deadline);
    stubExpectConnect($conn, $deadline);

    [$type, $body] = readPacket($conn, $deadline);
    check(0x03 === ($type >> 4), 'stub: expected PUBLISH');
    check(0 === ($type & 0x01), 'stub: publish must not be retained');
    check(1 === (($type >> 1) & 0x03), 'stub: publish must be QoS 1');
    $topicLength = unpack('n', substr($body, 0, 2))[1];
    check(MqttWateringPublisher::TOPIC.'/set' === substr($body, 2, $topicLength), 'stub: unexpected publish topic');

    // Deliberately withhold the PUBACK: the client must detect this itself.
    stubDrainUntil($conn, $deadline);

    return 0;
}

function stubMissingSuback(int $port): int
{
    $deadline = microtime(true) + 20;
    $conn = acceptOne($port, $deadline);
    stubExpectConnect($conn, $deadline);

    [$type] = readPacket($conn, $deadline);
    check(0x08 === ($type >> 4), 'stub: expected SUBSCRIBE');

    // Deliberately withhold the SUBACK.
    stubDrainUntil($conn, $deadline);

    return 0;
}

function stubRejectedSuback(int $port): int
{
    $deadline = microtime(true) + 20;
    $conn = acceptOne($port, $deadline);
    stubExpectConnect($conn, $deadline);

    [$type, $body] = readPacket($conn, $deadline);
    check(0x08 === ($type >> 4), 'stub: expected SUBSCRIBE');
    $messageId = unpack('n', substr($body, 0, 2))[1];
    // 0x80 = subscription rejected; the client library never registers a
    // rejected subscription, so this must look identical to "no SUBACK" downstream.
    writeAll($conn, "\x90\x03".pack('n', $messageId)."\x80", $deadline);
    stubDrainUntil($conn, $deadline);

    return 0;
}

// -------------------------------------------------------------------------
// Parent-process helpers for driving a stub and the code under test.
// -------------------------------------------------------------------------

/** @return array{resource, array<int, resource>} */
function spawnBrokerStub(string $mode, int $port): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'broker', $mode, (string) $port], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['redirect', 1],
    ], $pipes);
    check(is_resource($process), 'Could not start broker stub process.');

    stream_set_blocking($pipes[1], false);
    $ready = '';
    pollUntil(static function () use ($process, $pipes, &$ready): bool {
        $status = proc_get_status($process);
        check($status['running'], 'Broker stub exited early: '.$ready);
        $chunk = fgets($pipes[1]);
        if (false !== $chunk) {
            $ready .= $chunk;
        }

        return str_contains($ready, 'READY');
    }, 10, 'Broker stub did not become ready.');

    return [$process, $pipes];
}

/** @param array{resource, array<int, resource>} $handle */
function stopBrokerStub(array $handle): void
{
    [$process, $pipes] = $handle;
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    check(0 === $exit, 'Broker stub failed: '.$output);
}

function newRunner(Doctrine\DBAL\Connection $db, WateringPublisher $publisher, string $host, int $port, float $subackTimeout): WateringMonitorRunner
{
    $watering = new WateringManager($db, $publisher, true, 'dev');

    return new WateringMonitorRunner($watering, $publisher, new NullLogger(), $host, $port, 'test', 'test', $subackTimeout);
}

function heartbeatAt(Doctrine\DBAL\Connection $db): ?string
{
    $value = $db->fetchOne('SELECT monitor_seen_at FROM watering_control WHERE id = 1');

    return false === $value ? null : $value;
}

function resetControl(Doctrine\DBAL\Connection $db): void
{
    $db->executeStatement('DELETE FROM watering_run');
    $db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = NULL WHERE id = 1');
}

// -------------------------------------------------------------------------
// Scenarios
// -------------------------------------------------------------------------

function scenario_successful_puback(): void
{
    $subscriber = new MqttClient(MOSQUITTO_HOST, MOSQUITTO_PORT, 'ack-test-subscriber');
    $subscriber->connect(new ConnectionSettings(), true);
    $received = [];
    $subscriber->subscribe(MqttWateringPublisher::TOPIC.'/set', function (string $topic, string $message) use (&$received): void {
        $received[] = $message;
    }, MqttClient::QOS_AT_LEAST_ONCE);

    $publisher = new MqttWateringPublisher(MOSQUITTO_HOST, MOSQUITTO_PORT, 'test', 'test', 'dev');
    $publisher->publish(['watering_times' => 3, 'state' => 'ON']);

    pollUntil(static function () use ($subscriber, &$received): bool {
        $subscriber->loopOnce(microtime(true), true);

        return [] !== $received;
    }, 5, 'Publish with a successful PUBACK never reached the broker.');
    $subscriber->disconnect();

    check(['watering_times' => 3, 'state' => 'ON'] === json_decode($received[0], true), 'Delivered payload did not match.');
    echo "PASS successful-puback: publish() returns once the broker's PUBACK confirms delivery\n";
}

function scenario_withheld_puback(): void
{
    $handle = spawnBrokerStub('withhold-puback', 11901);
    try {
        $publisher = new MqttWateringPublisher(STUB_HOST, 11901, 'test', 'test', 'dev');
        $start = microtime(true);
        $threw = false;
        try {
            $publisher->publish(['watering_times' => 3, 'state' => 'ON']);
        } catch (RuntimeException $e) {
            $threw = true;
            check(str_contains($e->getMessage(), 'PUBACK'), 'Wrong exception for a withheld PUBACK: '.$e->getMessage());
        }
        check($threw, 'publish() did not detect a withheld PUBACK.');
        check(microtime(true) - $start >= 4.5, 'Ack-timeout detection returned before the loop() wait window elapsed.');
    } finally {
        stopBrokerStub($handle);
    }
    echo "PASS withheld-puback: publish() detects a PUBACK that never arrives and throws\n";
}

function scenario_missing_suback(Doctrine\DBAL\Connection $db): void
{
    resetControl($db);
    $handle = spawnBrokerStub('missing-suback', 11902);
    try {
        $runner = newRunner($db, new FakePublisher(), STUB_HOST, 11902, 1.0);
        $threw = false;
        try {
            $runner->runConnectionAttempt(static fn (): bool => false);
        } catch (RuntimeException $e) {
            $threw = true;
            check(str_contains($e->getMessage(), 'SUBACK'), 'Wrong exception for a missing SUBACK: '.$e->getMessage());
        }
        check($threw, 'Runner did not detect a missing SUBACK.');
        check(null === heartbeatAt($db), 'Heartbeat was set without a confirmed subscription.');
    } finally {
        stopBrokerStub($handle);
    }
    echo "PASS missing-suback: heartbeat never starts without a confirmed SUBACK\n";
}

function scenario_rejected_suback(Doctrine\DBAL\Connection $db): void
{
    resetControl($db);
    $handle = spawnBrokerStub('rejected-suback', 11903);
    try {
        $runner = newRunner($db, new FakePublisher(), STUB_HOST, 11903, 1.0);
        $threw = false;
        try {
            $runner->runConnectionAttempt(static fn (): bool => false);
        } catch (\Throwable $e) {
            // The pinned client defaults to MQTT 3.1 (matching the rest of this
            // codebase), whose wire format has no per-subscription rejection
            // code: the library itself rejects a QoS 128 SUBACK as malformed
            // rather than skipping it gracefully. Either way the subscription
            // is never registered, so the outcome we care about is unchanged.
            $threw = true;
            check(str_contains($e->getMessage(), 'QoS'), 'Wrong exception for a rejected SUBACK: '.$e->getMessage());
        }
        check($threw, 'Runner did not detect a rejected SUBACK.');
        check(null === heartbeatAt($db), 'Heartbeat was set despite a rejected subscription.');
    } finally {
        stopBrokerStub($handle);
    }
    echo "PASS rejected-suback: heartbeat never starts on a rejected SUBACK\n";
}

function scenario_suback_starts_and_invalidates_heartbeat(Doctrine\DBAL\Connection $db): void
{
    resetControl($db);
    $runner = newRunner($db, new FakePublisher(), MOSQUITTO_HOST, MOSQUITTO_PORT, 5.0);
    $calls = 0;
    $duringLoopHeartbeat = null;
    $runner->runConnectionAttempt(function () use (&$calls, &$duringLoopHeartbeat, $db): bool {
        if (0 === $calls++) {
            return false;
        }
        $duringLoopHeartbeat = heartbeatAt($db);

        return true;
    });

    check(null !== $duringLoopHeartbeat, 'Heartbeat was never set after a confirmed SUBACK.');
    check(null === heartbeatAt($db), 'Heartbeat was not invalidated once the connection attempt ended.');
    echo "PASS suback-starts-heartbeat: heartbeat starts only after SUBACK and is invalidated on disconnect\n";
}

function scenario_reconnect_resumes_heartbeat(Doctrine\DBAL\Connection $db): void
{
    resetControl($db);
    $publisher = new FakePublisher();
    $handle = spawnBrokerStub('missing-suback', 11904);
    try {
        $failingRunner = newRunner($db, $publisher, STUB_HOST, 11904, 1.0);
        $threw = false;
        try {
            $failingRunner->runConnectionAttempt(static fn (): bool => false);
        } catch (RuntimeException) {
            $threw = true;
        }
        check($threw, 'First connection attempt unexpectedly succeeded.');
        check(null === heartbeatAt($db), 'Heartbeat was set despite a failed first attempt.');
    } finally {
        stopBrokerStub($handle);
    }

    $recoveredRunner = newRunner($db, $publisher, MOSQUITTO_HOST, MOSQUITTO_PORT, 5.0);
    $calls = 0;
    $duringLoopHeartbeat = null;
    $recoveredRunner->runConnectionAttempt(function () use (&$calls, &$duringLoopHeartbeat, $db): bool {
        if (0 === $calls++) {
            return false;
        }
        $duringLoopHeartbeat = heartbeatAt($db);

        return true;
    });

    check(null !== $duringLoopHeartbeat, 'Heartbeat was not re-established after reconnecting to a working broker.');
    echo "PASS reconnect-resumes-heartbeat: a failed attempt never leaves a stale heartbeat, and a later successful attempt re-establishes it\n";
}

// -------------------------------------------------------------------------
// Entry point
// -------------------------------------------------------------------------

try {
    if (isset($argv[1]) && 'broker' === $argv[1]) {
        $mode = $argv[2];
        $port = (int) $argv[3];
        exit(match ($mode) {
            'withhold-puback' => stubWithholdPuback($port),
            'missing-suback' => stubMissingSuback($port),
            'rejected-suback' => stubRejectedSuback($port),
            default => throw new RuntimeException('Unknown broker stub mode: '.$mode),
        });
    }

    $kernel = new Kernel('dev', true);
    $kernel->boot();
    $db = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
    check('watering_control_test' === $db->getDatabase(), 'Refusing to use a non-test database.');

    scenario_successful_puback();
    scenario_withheld_puback();
    scenario_missing_suback($db);
    scenario_rejected_suback($db);
    scenario_suback_starts_and_invalidates_heartbeat($db);
    scenario_reconnect_resumes_heartbeat($db);

    echo "PASS watering MQTT acknowledgements: PUBACK detection and SUBACK-gated heartbeat lifecycle\n";
    $kernel->shutdown();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL watering-mqtt-acknowledgements: '.$e->getMessage()."\n");
    exit(1);
}
