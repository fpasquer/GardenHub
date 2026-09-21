<?php

declare(strict_types=1);

/*
 * Standalone CLI writer used by concurrency.php's "identity-first" scenario.
 * Boots its own kernel (no test hooks) and issues one real
 * POST /api/measurements, so the parent process can prove a genuine second
 * connection's write path actually blocks on the sensor's row lock instead
 * of merely running for a while, then observes the real outcome once
 * unblocked.
 *
 * argv: apiKey, sensorIri, deduplicationId, coordinationFilePath
 */

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require '/app/vendor/autoload.php';

final class MeasurementWriterKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-sensor-identity-writer/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-sensor-identity-writer/log';
    }
}

[, $apiKey, $sensorIri, $deduplicationId, $coordinationFile] = $argv;

$kernel = new MeasurementWriterKernel('dev', true);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();

// A DBAL Connection is a lazy, single persistent PDO session per process, so
// its connection id cannot change mid-process without an explicit reconnect,
// which nothing here triggers. Reporting it both here and after the request
// lets the parent empirically prove it observed the same connection that
// actually ran the request (see connectionId/connectionIdAfter below).
$connectionId = (int) $connection->fetchOne('SELECT CONNECTION_ID()');
file_put_contents($coordinationFile, json_encode(['connectionId' => $connectionId], JSON_THROW_ON_ERROR));

$request = Request::create('https://sensor-identity.test/api/measurements', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$apiKey,
    'HTTP_ACCEPT' => 'application/ld+json',
    'CONTENT_TYPE' => 'application/ld+json',
], json_encode([
    'sensor' => $sensorIri,
    'value' => 2.0,
    'measuredAt' => '2020-01-01T00:00:00+00:00',
    'deduplicationId' => $deduplicationId,
], JSON_THROW_ON_ERROR));

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

$connectionIdAfter = (int) $connection->fetchOne('SELECT CONNECTION_ID()');

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => $response->getContent(),
    'connectionId' => $connectionId,
    'connectionIdAfter' => $connectionIdAfter,
], JSON_THROW_ON_ERROR);

$kernel->shutdown();
