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
 * Opens an independent connection and proves a concurrent lock attempt on
 * the given sensor row genuinely blocks: it must fail with a bounded
 * lock-wait timeout, not succeed immediately.
 *
 * @param array{0: string, 1: string, 2: string} $pdoParts
 */
function assertLockBlocks(array $pdoParts, int $sensorId): void
{
    [$dsn, $user, $pass] = $pdoParts;
    $pdo = new \PDO($dsn, $user, $pass);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM sensor WHERE id = ? FOR UPDATE')->execute([$sensorId]);
        throw new RuntimeException('Expected the concurrent lock attempt to block and time out, but it succeeded immediately.');
    } catch (\PDOException $exception) {
        check(str_contains($exception->getMessage(), 'Lock wait timeout exceeded'), 'Expected a lock-wait-timeout error, got: '.$exception->getMessage());
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
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

    [$blockedResponse] = apiRequest($kernel, $apiKey, 'PATCH', $sensor1Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
    check($blockedResponse->getStatusCode() >= 500, 'A Sensor update contending for a held row lock must fail (infra-level), not silently succeed or return 422. Got: '.$blockedResponse->getStatusCode());

    $writer->commit();

    [$retryResponse, $retryBody] = apiRequest($kernel, $apiKey, 'PATCH', $sensor1Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $retryResponse->getStatusCode(), 'Once uncontended, the update must see the committed measurement and reject. Got: '.$retryResponse->getContent());
    check(violationPath($retryBody, 'type'), 'The rejection must be reported on the type field.');
    echo "PASS measurement-first: a concurrent Sensor update genuinely blocks, then correctly rejects once it observes the committed measurement\n";

    // -----------------------------------------------------------------
    // 2. A Sensor update holds the lock first; a concurrent lock attempt on
    //    an independent connection genuinely blocks while it is in flight.
    // -----------------------------------------------------------------
    [, $device2] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Concurrency device 2', 'devEui' => 'conc-dev-2']);
    [, $sensor2] = apiRequest($kernel, $apiKey, 'POST', '/api/sensors', ['device' => $device2['@id'], 'type' => 'temp_a', 'unit' => '°C', 'label' => 'S2']);
    $sensor2Iri = $sensor2['@id'];
    $sensor2Id = (int) $sensor2['id'];

    $blockProven = false;
    SensorLockObserverMiddleware::arm(new SensorLockObserverConfig($sensor2Id, function () use ($pdoParts, $sensor2Id, &$blockProven): void {
        assertLockBlocks($pdoParts, $sensor2Id);
        $blockProven = true;
    }));

    [$updateResponse] = apiRequest($kernel, $apiKey, 'PATCH', $sensor2Iri, ['type' => 'temp_b'], 'application/merge-patch+json');
    SensorLockObserverMiddleware::disarm();

    check($blockProven, 'The concurrent lock attempt must have run and proven blocking.');
    check(Response::HTTP_OK === $updateResponse->getStatusCode(), 'The Sensor update itself must still succeed once the observer releases control. Got: '.$updateResponse->getContent());
    echo "PASS identity-first: a concurrent lock attempt on an independent connection genuinely blocks while the Sensor update is in flight\n";

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
