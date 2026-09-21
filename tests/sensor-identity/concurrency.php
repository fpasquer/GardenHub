<?php

declare(strict_types=1);

/*
 * Genuine-concurrency regressions for sensor-identity protection.
 *
 * Unlike sensor-identity.php (sequential HTTP scenarios), these prove the
 * locking primitive shared by every writer (SensorRepository::lockAndFetchIdentity())
 * actually serializes concurrent writers on independent connections, in both
 * commit orders, with bounded timeouts so nothing can hang:
 *
 *  1. A measurement writer holds the sensor's row lock first (uncommitted):
 *     a concurrent Sensor update genuinely blocks (proven via a bounded
 *     lock-wait timeout), then correctly rejects once it can proceed and
 *     observes the just-committed measurement.
 *  2. A Sensor update holds the row lock first: a concurrent lock attempt on
 *     an independent connection genuinely blocks while the update is still
 *     in flight (uncommitted), proven the same way.
 *  3. A Measurement-create request's own sensor identity changes, via a real
 *     second connection, between its deserialization and its lock: it is
 *     rejected (422), not silently persisted against stale data.
 */

use App\Entity\ApiClient;
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Tests\SensorIdentity\ExceptionCaptureListener;
use Tests\SensorIdentity\SensorLockObserverConfig;
use Tests\SensorIdentity\SensorLockObserverMiddleware;
use Tests\SensorIdentity\SensorPostLoadRelabelConfig;
use Tests\SensorIdentity\SensorPostLoadRelabeler;

require '/app/vendor/autoload.php';

// Test-only classes under /tests/src are not in the Composer autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\SensorIdentity\\';
    if (str_starts_with($class, $prefix)) {
        require '/tests/src/'.substr($class, strlen($prefix)).'.php';
    }
});

final class ConcurrencyKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-sensor-identity-concurrency/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-sensor-identity-concurrency/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Test-only hooks on the shared default DBAL connection / Doctrine
        // event manager. Production never registers these.
        $container->register('test.sensor_lock_observer_middleware', SensorLockObserverMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->register('test.sensor_postload_relabeler', SensorPostLoadRelabeler::class)
            ->addTag('doctrine.event_listener', ['event' => 'postLoad']);
        // High priority so this observes the original throwable before any
        // other kernel.exception listener replaces it (e.g. with a rendered
        // HttpException), which would hide the real DBAL exception chain.
        $container->register('test.exception_capture_listener', ExceptionCaptureListener::class)
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 2048])
            ->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{Response, array<string, mixed>|null}
 */
function apiRequest(ConcurrencyKernel $kernel, string $apiKey, string $method, string $uri, ?array $body = null, string $contentType = 'application/ld+json'): array
{
    $server = [
        'HTTP_AUTHORIZATION' => 'Bearer '.$apiKey,
        'HTTP_ACCEPT' => 'application/ld+json',
        'CONTENT_TYPE' => $contentType,
    ];
    $content = null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null;

    $request = Request::create('https://sensor-identity.test'.$uri, $method, [], [], [], $server, $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $decoded = null;
    $raw = $response->getContent();
    if (is_string($raw) && '' !== $raw) {
        $decoded = json_decode($raw, true);
    }

    return [$response, is_array($decoded) ? $decoded : null];
}

/**
 * @param array<string, mixed>|null $body
 */
function violationPath(?array $body, string $expectedPath): bool
{
    foreach ($body['violations'] ?? [] as $violation) {
        if (($violation['propertyPath'] ?? null) === $expectedPath) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{0: string, 1: string, 2: string} [dsn, user, password]
 */
function pdoParts(): array
{
    $dbParts = parse_url((string) getenv('DATABASE_URL'));

    return [
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbParts['host'], $dbParts['port'] ?? 3306, ltrim($dbParts['path'], '/')),
        $dbParts['user'] ?? 'root',
        $dbParts['pass'] ?? '',
    ];
}

/**
 * Fails fast, with a clear cause, if this DB user cannot read the
 * performance_schema tables the lock-wait proof below depends on - instead
 * of a confusing timeout deep inside a scenario.
 */
function assertLockWaitMetadataAccessible(\Doctrine\DBAL\Connection $connection): void
{
    try {
        $connection->fetchOne('SELECT COUNT(*) FROM performance_schema.threads');
        $connection->fetchOne('SELECT COUNT(*) FROM performance_schema.data_lock_waits');
    } catch (\Throwable $exception) {
        throw new RuntimeException('Test DB user cannot read performance_schema.threads/data_lock_waits, required to prove a genuine lock wait.', 0, $exception);
    }
}

/**
 * Walks the exception chain for a genuine InnoDB lock-wait timeout, rather
 * than trusting a generic HTTP status or searching rendered response text.
 */
function isLockWaitTimeout(?\Throwable $throwable): bool
{
    while (null !== $throwable) {
        if ($throwable instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            return true;
        }
        if ($throwable instanceof \Doctrine\DBAL\Driver\Exception && 1205 === $throwable->getCode()) {
            return true;
        }
        $throwable = $throwable->getPrevious();
    }

    return false;
}

/**
 * Bounded-polls for the writer subprocess's coordination file and returns
 * the MySQL connection id it reports, so the caller can prove a genuine
 * lock wait between that specific connection and its own.
 */
function waitForCoordinationConnectionId(string $path, float $timeoutSeconds): int
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $contents = @file_get_contents($path);
        if (false !== $contents && '' !== $contents) {
            $decoded = json_decode($contents, true);
            if (is_array($decoded) && isset($decoded['connectionId'])) {
                return (int) $decoded['connectionId'];
            }
        }
        usleep(20000);
    }

    throw new RuntimeException('Timed out waiting for the writer subprocess to report its connection id.');
}

/**
 * Bounded-polls MySQL's own lock-wait metadata until it confirms the
 * requesting connection is genuinely blocked on a lock held by the
 * blocking connection - no timing assumption, only the engine's own state.
 */
function waitForLockWait(\Doctrine\DBAL\Connection $connection, int $requestingConnectionId, int $blockingConnectionId, float $timeoutSeconds): void
{
    $sql = 'SELECT COUNT(*) FROM performance_schema.data_lock_waits w'
        .' JOIN performance_schema.threads rt ON rt.THREAD_ID = w.REQUESTING_THREAD_ID'
        .' JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID'
        .' WHERE rt.PROCESSLIST_ID = ? AND bt.PROCESSLIST_ID = ?';

    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        if ((int) $connection->fetchOne($sql, [$requestingConnectionId, $blockingConnectionId]) > 0) {
            return;
        }
        usleep(20000);
    }

    throw new RuntimeException('Timed out waiting for MySQL to report a genuine lock wait between the writer and this connection.');
}

/**
 * Bounded-polls a proc_open()'d process until it has exited, collecting its
 * stdout/stderr non-blockingly. Does not close pipes or the process handle;
 * the caller owns that so cleanup happens exactly once.
 *
 * @param resource              $process
 * @param array<int, resource>  $pipes
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function waitForProcessExit($process, array $pipes, float $timeoutSeconds): array
{
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';

    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            return ['exitCode' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
        }
        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for the measurement-writer subprocess to exit.');
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new ConcurrencyKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    check('sensor_identity' === $connection->getDatabase(), 'Refusing to modify a non-test database.');

    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schema = new SchemaTool($entityManager);
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);
    $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
    // Bounds this connection's own lock waits so a genuinely contended
    // request fails fast (proving blocking) instead of hanging.
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
    assertLockWaitMetadataAccessible($connection);

    $exceptionCapture = $container->get('test.exception_capture_listener');

    $apiKey = 'gh_test_'.bin2hex(random_bytes(16));
    $apiClient = (new ApiClient())
        ->setName('sensor-identity-concurrency-tests')
        ->setKeyHash(hash('sha256', $apiKey))
        ->setRoles([ApiClient::ROLE_READ, ApiClient::ROLE_WRITE]);
    $entityManager->persist($apiClient);
    $entityManager->flush();

    $pdoParts = pdoParts();

    // -----------------------------------------------------------------
    // 1. Measurement writer holds the lock first.
    // -----------------------------------------------------------------
    [, $device1] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Concurrency device 1', 'devEui' => 'conc-dev-1']);
    [, $sensor1] = apiRequest($kernel, $apiKey, 'POST', '/api/sensors', ['device' => $device1['@id'], 'type' => 'temp_a', 'unit' => '°C', 'label' => 'S1']);
    $sensor1Iri = $sensor1['@id'];
    $sensor1Id = (int) $sensor1['id'];

    [$dsn, $user, $pass] = $pdoParts;
    $writer = new \PDO($dsn, $user, $pass);
    $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $writer->beginTransaction();
    $writer->prepare('SELECT id FROM sensor WHERE id = ? FOR UPDATE')->execute([$sensor1Id]);
    $dedup1 = (string) Uuid::v4();
    $writer->prepare('INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (?, 1.0, NOW(), NOW(), ?, ?)')
        ->execute([$sensor1Id, $dedup1, 'temp_a']);
    // Deliberately left open/uncommitted: simulates another writer (API or
    // MQTT) that already claimed this row's lock and is about to commit.

    $exceptionCapture->reset();
    [$blockedResponse] = apiRequest($kernel, $apiKey, 'PATCH', $sensor1Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
    check($blockedResponse->getStatusCode() >= 500, 'A Sensor update contending for a held row lock must fail (infra-level), not silently succeed or return 422. Got: '.$blockedResponse->getStatusCode());
    check(isLockWaitTimeout($exceptionCapture->captured()), 'The blocked request must fail because of a genuine InnoDB lock-wait timeout, not an unrelated 500. Got: '.($exceptionCapture->captured()?->getMessage() ?? 'no exception captured'));

    $writer->commit();

    [$retryResponse, $retryBody] = apiRequest($kernel, $apiKey, 'PATCH', $sensor1Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $retryResponse->getStatusCode(), 'Once uncontended, the update must see the committed measurement and reject. Got: '.$retryResponse->getContent());
    check(violationPath($retryBody, 'type'), 'The rejection must be reported on the type field.');

    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$dedup1]), 'The writer\'s committed measurement must remain stored exactly once.');
    $sensor1Row = $connection->fetchAssociative('SELECT device_id, type, unit, label FROM sensor WHERE id = ?', [$sensor1Id]);
    check((int) $device1['id'] === (int) $sensor1Row['device_id'], 'The rejected update must not have changed the sensor\'s device.');
    check('temp_a' === $sensor1Row['type'], 'The rejected update must not have changed the sensor\'s type.');
    check('°C' === $sensor1Row['unit'], 'The rejected update must not have changed the sensor\'s unit.');
    check('S1' === $sensor1Row['label'], 'The rejected update must not have changed the sensor\'s label.');
    echo "PASS measurement-first: a concurrent Sensor update genuinely blocks on a real lock-wait timeout, then correctly rejects once it observes the committed measurement, leaving the sensor's identity untouched\n";

    // -----------------------------------------------------------------
    // 2. A Sensor update holds the lock first; a concurrent Measurement
    //    writer on an independent process genuinely blocks on the same row
    //    lock while the update is still in flight, then correctly rejects
    //    once it observes the update it waited on.
    // -----------------------------------------------------------------
    [, $device2] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Concurrency device 2', 'devEui' => 'conc-dev-2']);
    [, $sensor2] = apiRequest($kernel, $apiKey, 'POST', '/api/sensors', ['device' => $device2['@id'], 'type' => 'temp_a', 'unit' => '°C', 'label' => 'S2']);
    $sensor2Iri = $sensor2['@id'];
    $sensor2Id = (int) $sensor2['id'];

    $dedup2 = (string) Uuid::v4();
    $coordinationFile = tempnam(sys_get_temp_dir(), 'gardenhub-writer-');
    @unlink($coordinationFile);
    $parentConnectionId = (int) $connection->fetchOne('SELECT CONNECTION_ID()');

    $writerProcess = null;
    $pipes = [];
    try {
        SensorLockObserverMiddleware::arm(new SensorLockObserverConfig($sensor2Id, function () use ($apiKey, $sensor2Iri, $dedup2, $coordinationFile, $connection, $parentConnectionId, &$writerProcess, &$pipes): void {
            $opened = proc_open(
                ['php', '/tests/src/measurement-writer.php', $apiKey, $sensor2Iri, $dedup2, $coordinationFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            check(false !== $opened, 'Failed to spawn the measurement-writer subprocess.');
            $writerProcess = $opened;

            $writerConnectionId = waitForCoordinationConnectionId($coordinationFile, 15.0);
            waitForLockWait($connection, $writerConnectionId, $parentConnectionId, 5.0);
        }));

        [$updateResponse] = apiRequest($kernel, $apiKey, 'PATCH', $sensor2Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
        SensorLockObserverMiddleware::disarm();

        check(Response::HTTP_OK === $updateResponse->getStatusCode(), 'The Sensor update itself must still succeed once the writer\'s lock-wait is proven. Got: '.$updateResponse->getContent());
        check(is_resource($writerProcess), 'The measurement-writer subprocess must have been spawned.');

        $writerResult = waitForProcessExit($writerProcess, $pipes, 15.0);
        check(0 === $writerResult['exitCode'], 'The measurement-writer subprocess must exit successfully. Stderr: '.$writerResult['stderr']);
        $writerOutput = json_decode($writerResult['stdout'], true, 512, JSON_THROW_ON_ERROR);

        check($writerOutput['connectionId'] === $writerOutput['connectionIdAfter'], 'The writer must use the same MySQL connection throughout its request (no reconnect), or the lock-wait proof does not apply to the request that actually ran.');
        check(Response::HTTP_UNPROCESSABLE_ENTITY === $writerOutput['status'], 'The blocked writer must be rejected once it observes the Sensor update it waited on. Got: '.$writerOutput['body']);
        check(violationPath(json_decode($writerOutput['body'], true), 'sensor'), 'The rejection must be reported on the sensor field.');
        check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$dedup2]), 'No measurement may be persisted once its sensor identity changed while it waited on the lock.');
        echo "PASS identity-first: a concurrent Measurement writer genuinely blocks on the row lock, then correctly rejects once it observes the Sensor update it waited on\n";
    } finally {
        SensorLockObserverMiddleware::disarm();
        if (is_resource($writerProcess)) {
            proc_terminate($writerProcess);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($writerProcess);
        }
        @unlink($coordinationFile);
    }

    // -----------------------------------------------------------------
    // 3. A Measurement-create request's sensor identity changes, via a real
    //    second connection, between its deserialization and its lock.
    // -----------------------------------------------------------------
    [, $device3] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Concurrency device 3', 'devEui' => 'conc-dev-3']);
    [, $sensor3] = apiRequest($kernel, $apiKey, 'POST', '/api/sensors', ['device' => $device3['@id'], 'type' => 'temp_a', 'unit' => '°C', 'label' => 'S3']);
    $sensor3Iri = $sensor3['@id'];
    $sensor3Id = (int) $sensor3['id'];

    SensorPostLoadRelabeler::arm(new SensorPostLoadRelabelConfig($sensor3Id, 'temp_b', ...$pdoParts));

    $dedup3 = (string) Uuid::v4();
    [$createResponse, $createBody] = apiRequest($kernel, $apiKey, 'POST', '/api/measurements', [
        'sensor' => $sensor3Iri,
        'value' => 2.0,
        'measuredAt' => '2020-01-01T00:00:00+00:00',
        'deduplicationId' => $dedup3,
    ]);
    SensorPostLoadRelabeler::disarm();

    check(Response::HTTP_UNPROCESSABLE_ENTITY === $createResponse->getStatusCode(), 'A Measurement whose sensor identity drifted mid-request must be rejected. Got: '.$createResponse->getContent());
    check(violationPath($createBody, 'sensor'), 'The rejection must be reported on the sensor field.');

    $stored = (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$dedup3]);
    check(0 === $stored, 'No measurement may be persisted when the sensor identity drifted mid-request.');
    $currentType = (string) $connection->fetchOne('SELECT type FROM sensor WHERE id = ?', [$sensor3Id]);
    check('temp_b' === $currentType, 'The independently-committed concurrent identity change must not be rolled back by the rejected measurement.');
    echo "PASS measurement-create: a mid-request sensor identity change is detected and rejected, not silently persisted against stale data\n";

    echo "PASS concurrency: both lock orderings proven blocking and correct; Measurement-create rejects a mid-request identity drift\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
