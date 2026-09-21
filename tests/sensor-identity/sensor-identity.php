<?php

declare(strict_types=1);

/*
 * Sensor-identity protection regressions exercised through the real API
 * update path (HTTP requests dispatched through the Symfony kernel).
 *
 * Covered behaviors:
 *  - a new sensor's device/type/unit are freely settable
 *  - device/type/unit remain freely editable while a sensor has no measurements
 *  - once a sensor has a measurement, device/type/unit changes are rejected (422)
 *  - label remains editable even after measurements exist
 *  - resubmitting the sensor's existing (unchanged) identity values succeeds
 *    (compared against persisted state, not PHP object identity)
 *  - changing a protected field together with the label rejects the whole
 *    request; the label is not partially persisted
 *
 * Concurrent/racing writers are covered separately in concurrency.php.
 */

use App\Entity\ApiClient;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';

final class SensorIdentityKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-sensor-identity/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-sensor-identity/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Sends one HTTP request through the real kernel and decodes its JSON body.
 *
 * @return array{Response, array<string, mixed>|null}
 */
function apiRequest(SensorIdentityKernel $kernel, string $apiKey, string $method, string $uri, ?array $body = null, string $contentType = 'application/ld+json'): array
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

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new SensorIdentityKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    check('sensor_identity' === $connection->getDatabase(), 'Refusing to modify a non-test database.');

    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schema = new SchemaTool($entityManager);
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);
    $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');

    $apiKey = 'gh_test_'.bin2hex(random_bytes(16));
    $apiClient = (new ApiClient())
        ->setName('sensor-identity-tests')
        ->setKeyHash(hash('sha256', $apiKey))
        ->setRoles([ApiClient::ROLE_READ, ApiClient::ROLE_WRITE]);
    $entityManager->persist($apiClient);
    $entityManager->flush();

    // -----------------------------------------------------------------
    // 1. A new sensor's device/type/unit are freely settable.
    // -----------------------------------------------------------------
    [$response, $device1] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Identity device 1', 'devEui' => 'sid-dev-1']);
    check(Response::HTTP_CREATED === $response->getStatusCode(), 'Device creation must succeed. Got: '.$response->getContent());
    $device1Iri = $device1['@id'];

    [$response, $device2] = apiRequest($kernel, $apiKey, 'POST', '/api/devices', ['name' => 'Identity device 2', 'devEui' => 'sid-dev-2']);
    check(Response::HTTP_CREATED === $response->getStatusCode(), 'Second device creation must succeed.');
    $device2Iri = $device2['@id'];

    [$response, $sensor] = apiRequest($kernel, $apiKey, 'POST', '/api/sensors', ['device' => $device1Iri, 'type' => 'temp_a', 'unit' => '°C', 'label' => 'Sensor under test']);
    check(Response::HTTP_CREATED === $response->getStatusCode(), 'A brand-new sensor must accept any device/type/unit. Got: '.$response->getContent());
    $sensorIri = $sensor['@id'];
    $sensorId = (int) $sensor['id'];
    echo "PASS create: a new sensor's device/type/unit are freely settable\n";

    // -----------------------------------------------------------------
    // 2. device/type/unit remain freely editable before any measurement.
    // -----------------------------------------------------------------
    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['type' => 'temp_b'], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode() && 'temp_b' === $sensor['type'], 'type must be freely editable before any measurement. Got: '.$response->getContent());

    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['unit' => 'V'], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode() && 'V' === $sensor['unit'], 'unit must be freely editable before any measurement.');

    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['device' => $device2Iri], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode() && $device2Iri === $sensor['device']['@id'], 'device must be freely editable before any measurement. Got: '.$response->getContent());

    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['device' => $device1Iri], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode(), 'Moving the sensor back to device 1 must still succeed pre-measurement.');
    echo "PASS pre-measurement: device/type/unit remain freely editable until a measurement exists\n";

    // -----------------------------------------------------------------
    // 3. Once a measurement exists, protected fields are rejected.
    // -----------------------------------------------------------------
    $deduplicationId = (string) Uuid::v4();
    [$response] = apiRequest($kernel, $apiKey, 'POST', '/api/measurements', [
        'sensor' => $sensorIri,
        'value' => 1.23,
        'measuredAt' => '2020-01-01T00:00:00+00:00',
        'deduplicationId' => $deduplicationId,
    ]);
    check(Response::HTTP_CREATED === $response->getStatusCode(), 'The first measurement must be accepted. Got: '.$response->getContent());
    echo "PASS measurement: the sensor's first measurement is accepted\n";

    [$response, $body] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['type' => 'temp_c'], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode() && violationPath($body, 'type'), 'type must be rejected once a measurement exists. Got: '.$response->getContent());

    [$response, $body] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['unit' => 'A'], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode() && violationPath($body, 'unit'), 'unit must be rejected once a measurement exists. Got: '.$response->getContent());

    [$response, $body] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['device' => $device2Iri], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode() && violationPath($body, 'device'), 'device must be rejected once a measurement exists. Got: '.$response->getContent());
    echo "PASS protected: device/type/unit changes are rejected (422) once a measurement exists\n";

    // -----------------------------------------------------------------
    // 4. The label always stays editable.
    // -----------------------------------------------------------------
    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, ['label' => 'Renamed after measurement'], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode() && 'Renamed after measurement' === $sensor['label'], 'label must remain editable after measurements exist. Got: '.$response->getContent());
    echo "PASS label: label remains editable after measurements exist\n";

    // -----------------------------------------------------------------
    // 5. Resubmitting the existing identity (unchanged) must succeed, even
    //    together with a label change (compared by persisted value, not by
    //    PHP object identity).
    // -----------------------------------------------------------------
    [$response, $sensor] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, [
        'device' => $device1Iri,
        'type' => 'temp_b',
        'unit' => 'V',
        'label' => 'Still the same sensor',
    ], 'application/merge-patch+json');
    check(Response::HTTP_OK === $response->getStatusCode() && 'Still the same sensor' === $sensor['label'], 'Resubmitting the unchanged identity must succeed. Got: '.$response->getContent());
    echo "PASS resubmit: resubmitting the sensor's existing identity values succeeds\n";

    // -----------------------------------------------------------------
    // 6. Changing a protected field together with the label rejects the
    //    whole request; the label must not be partially persisted.
    // -----------------------------------------------------------------
    [$response, $body] = apiRequest($kernel, $apiKey, 'PATCH', $sensorIri, [
        'type' => 'temp_d',
        'label' => 'Should never be persisted',
    ], 'application/merge-patch+json');
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode() && violationPath($body, 'type'), 'A combined protected+label change must be rejected entirely. Got: '.$response->getContent());

    [, $sensor] = apiRequest($kernel, $apiKey, 'GET', $sensorIri);
    check('Still the same sensor' === $sensor['label'], 'The label must not be persisted when the same request also changes a protected field.');
    check('temp_b' === $sensor['type'], 'The type must not be persisted when the request is rejected.');
    echo "PASS atomic: a rejected combined update leaves no partial change (label included)\n";

    $storedCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE sensor_id = ?', [$sensorId]);
    check(1 === $storedCount, 'Exactly one measurement must remain stored for this sensor throughout.');

    echo "PASS sensor-identity: create, pre-measurement edits, post-measurement rejection, label exemption, identity resubmission, atomic rejection\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
