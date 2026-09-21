<?php

declare(strict_types=1);

/*
 * HTTP-level regression for the custom alert-incident POST operations
 * (finding 1): with write:false removed, POST /alert_incidents/{id}/acknowledge
 * and /resolve must actually execute their processors and persist.
 *
 * Covered behaviors (real kernel, real MySQL, real API Platform pipeline):
 *  - acknowledge as admin: 200, acknowledged_at/acknowledged_by persisted,
 *    status untouched
 *  - resolve as admin (active processing_failure + reason): 200, status,
 *    resolved_by and resolution_reason persisted
 *  - rejections without any persisted change: no key (401), invalid key
 *    (401), non-admin key (403), missing reason (422), overlong reason
 *    (422), resolving a non-processing_failure incident (422), resolving a
 *    PENDING processing_failure incident (422), resolving an already
 *    resolved incident (422)
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Entity\AlertEvaluationProgress;
use App\Entity\AlertIncident;
use App\Entity\ApiClient;
use App\Kernel;
use App\Repository\AlertEvaluationProgressRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';

final class IncidentApiKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-incident-api/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-incident-api/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resetDatabase(Connection $connection): void
{
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Refusing to reset schema outside the isolated test Compose file.');
    check(TEST_DATABASE === $connection->getDatabase(), 'Refusing to reset a non-test database.');

    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $tables = $connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [TEST_DATABASE],
        );
        foreach ($tables as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$connection->quoteIdentifier($table));
        }
    } finally {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function runMigrations(Application $application): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, 'Migrations failed: '.$output->fetch());
}

/** @return array{Response, array<string, mixed>|null} */
function apiRequest(IncidentApiKernel $kernel, ?string $apiKey, string $method, string $uri, ?array $body = null): array
{
    $server = [
        'HTTP_ACCEPT' => 'application/ld+json',
        'CONTENT_TYPE' => 'application/ld+json',
    ];
    if (null !== $apiKey) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$apiKey;
    }
    $content = null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null;

    $request = Request::create('https://incident-api.test'.$uri, $method, [], [], [], $server, $content);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $decoded = null;
    $raw = $response->getContent();
    if (is_string($raw) && '' !== $raw) {
        $decoded = json_decode($raw, true);
    }

    return [$response, is_array($decoded) ? $decoded : null];
}

/** @return array<string, mixed> */
function incidentRow(Connection $connection, int $id): array
{
    $row = $connection->fetchAssociative('SELECT * FROM alert_incident WHERE id = ?', [$id]);
    check(is_array($row), sprintf('Incident #%d must exist.', $id));

    return $row;
}

/** Seeds one incident via the real lifecycle and returns its database id. */
function seedIncident(EntityManagerInterface $em, MessageBusInterface $bus, string $alertType, string $subjectKey, int $confirmationThreshold): int
{
    /** @var \App\Repository\AlertIncidentRepository $repository */
    $repository = $em->getRepository(AlertIncident::class);
    /** @var AlertEvaluationProgressRepository $progressRepository */
    $progressRepository = $em->getRepository(AlertEvaluationProgress::class);
    $lifecycle = new AlertLifecycleService($repository, $progressRepository, $em, $bus, new MockClock('2026-01-01T00:00:00Z'), reminderIntervalSeconds: 3600);

    $lifecycle->openOrAdvance($alertType, $subjectKey, new AlertSignal(breach: true, context: 'seeded failure'), $confirmationThreshold, recoveryThreshold: 1);

    return (int) $em->getConnection()->fetchOne(
        'SELECT id FROM alert_incident WHERE alert_type = ? AND subject_key = ?',
        [$alertType, $subjectKey],
    );
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new IncidentApiKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);

    $application = new Application($kernel);
    $application->setAutoExit(false);
    runMigrations($application);
    $connection->close();

    $messageBus = $container->get('test.messenger.default_bus');

    $adminKey = 'gh_admin_'.bin2hex(random_bytes(16));
    $writerKey = 'gh_writer_'.bin2hex(random_bytes(16));
    $entityManager->persist((new ApiClient())->setName('incident-api-admin')->setKeyHash(hash('sha256', $adminKey))->setRoles([ApiClient::ROLE_READ, ApiClient::ROLE_WRITE, ApiClient::ROLE_ADMIN]));
    $entityManager->persist((new ApiClient())->setName('incident-api-writer')->setKeyHash(hash('sha256', $writerKey))->setRoles([ApiClient::ROLE_READ, ApiClient::ROLE_WRITE]));
    $entityManager->flush();

    // Fixtures: an ACTIVE processing_failure, an ACTIVE non-processing_failure
    // and a PENDING processing_failure incident.
    $activeFailureId = seedIncident($entityManager, $messageBus, 'processing_failure', 'chirpstack_uplink:ack-target', 1);
    $activeOtherId = seedIncident($entityManager, $messageBus, 'soil_too_dry', 'sensor:ack-target', 1);
    $pendingFailureId = seedIncident($entityManager, $messageBus, 'processing_failure', 'chirpstack_uplink:pending-target', 3);

    // -- Acknowledge ---------------------------------------------------
    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/acknowledge', $activeFailureId));
    check(Response::HTTP_OK === $response->getStatusCode(), 'Admin acknowledge must return 200. Got: '.$response->getStatusCode().' '.$response->getContent());
    $row = incidentRow($connection, $activeFailureId);
    check(null !== $row['acknowledged_at'], 'acknowledged_at must be persisted.');
    check('incident-api-admin' === $row['acknowledged_by'], 'acknowledged_by must hold the API client name.');
    check(AlertIncident::STATUS_ACTIVE === $row['status'], 'Acknowledging must not change the status.');
    echo "PASS acknowledge: admin acknowledge persists acknowledged_at/acknowledged_by without touching the status\n";

    // -- Resolve (happy path) -------------------------------------------
    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $activeFailureId), ['resolutionReason' => 'fixed by deploy']);
    check(Response::HTTP_OK === $response->getStatusCode(), 'Admin resolve must return 200. Got: '.$response->getStatusCode().' '.$response->getContent());
    $row = incidentRow($connection, $activeFailureId);
    check(AlertIncident::STATUS_RESOLVED === $row['status'], 'status must be persisted as resolved.');
    check(null !== $row['resolved_at'] && 'incident-api-admin' === $row['resolved_by'], 'resolved_at/resolved_by must be persisted.');
    check('fixed by deploy' === $row['resolution_reason'], 'resolution_reason must be persisted.');
    echo "PASS resolve: admin resolve of an active processing_failure incident persists status, actor and reason\n";

    // -- Rejections (persisted state must not change) --------------------
    $before = incidentRow($connection, $activeOtherId);

    [$response] = apiRequest($kernel, null, 'POST', sprintf('/api/alert_incidents/%d/acknowledge', $activeOtherId));
    check(Response::HTTP_UNAUTHORIZED === $response->getStatusCode(), 'Missing key must be rejected with 401. Got: '.$response->getStatusCode());

    [$response] = apiRequest($kernel, 'gh_invalid_'.bin2hex(random_bytes(8)), 'POST', sprintf('/api/alert_incidents/%d/acknowledge', $activeOtherId));
    check(Response::HTTP_UNAUTHORIZED === $response->getStatusCode(), 'An invalid key must be rejected with 401. Got: '.$response->getStatusCode());

    [$response] = apiRequest($kernel, $writerKey, 'POST', sprintf('/api/alert_incidents/%d/acknowledge', $activeOtherId));
    check(Response::HTTP_FORBIDDEN === $response->getStatusCode(), 'A non-admin key must be rejected with 403. Got: '.$response->getStatusCode());

    [$response] = apiRequest($kernel, $writerKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $activeOtherId), ['resolutionReason' => 'nope']);
    check(Response::HTTP_FORBIDDEN === $response->getStatusCode(), 'Resolve with a non-admin key must be rejected with 403. Got: '.$response->getStatusCode());
    check($before === incidentRow($connection, $activeOtherId), 'Rejected requests must not change persisted state.');
    echo "PASS rejections-auth: missing/invalid/non-admin credentials are rejected (401/403) without persisted changes\n";

    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $pendingFailureId), []);
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'A missing resolution reason must be rejected with 422. Got: '.$response->getStatusCode().' '.$response->getContent());

    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $pendingFailureId), ['resolutionReason' => str_repeat('x', 501)]);
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'An overlong resolution reason must be rejected with 422. Got: '.$response->getStatusCode());
    $pendingRow = incidentRow($connection, $pendingFailureId);
    check(AlertIncident::STATUS_PENDING === $pendingRow['status'] && null === $pendingRow['resolution_reason'], 'Rejected resolves must not change the pending incident.');
    echo "PASS rejections-validation: missing and overlong resolution reasons are rejected with 422 without persisted changes\n";

    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $activeOtherId), ['resolutionReason' => 'manually closed']);
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'Resolving a non-processing_failure incident must be rejected with 422. Got: '.$response->getStatusCode());
    check(AlertIncident::STATUS_ACTIVE === incidentRow($connection, $activeOtherId)['status'], 'The non-processing_failure incident must stay active.');

    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $pendingFailureId), ['resolutionReason' => 'manually closed']);
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'Resolving a PENDING processing_failure incident must be rejected with 422. Got: '.$response->getStatusCode());
    check(AlertIncident::STATUS_PENDING === incidentRow($connection, $pendingFailureId)['status'], 'The pending incident must stay pending.');

    [$response] = apiRequest($kernel, $adminKey, 'POST', sprintf('/api/alert_incidents/%d/resolve', $activeFailureId), ['resolutionReason' => 'again']);
    check(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'Resolving an already resolved incident must be rejected with 422. Got: '.$response->getStatusCode());
    check('fixed by deploy' === incidentRow($connection, $activeFailureId)['resolution_reason'], 'The already resolved incident must keep its original reason.');
    echo "PASS rejections-scope: only an ACTIVE processing_failure incident can be manually resolved; everything else is a 422 no-op\n";

    echo "PASS incident api: acknowledge/resolve processors execute over HTTP and every invalid request leaves persisted state untouched\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
