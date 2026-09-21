<?php

declare(strict_types=1);

/*
 * Durable replay/ordering protection for AlertLifecycleService (finding 4):
 * evaluation progress lives in alert_evaluation_progress, independently of
 * any open incident, so protection survives incident resolution and the
 * healthy period before the first breach. Covered here:
 *
 *  1. breach → resolution → replay of the old breach: no new incident.
 *  2. healthy reading → older-measuredAt breach (higher id): no false
 *     incident — ids do not establish measurement-time order, the
 *     measured_at watermark does.
 *  3. equal-timestamp reading: considered (arrival order applies).
 *  4. duplicate evaluation: no counter increment, also after resolution.
 *  5. genuinely newer breach after resolution: normal new-incident flow.
 *  6. transaction failure + retry: no lost evaluation, no duplicate effect.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Alert\Message\SendTelegramNotification;
use App\Entity\AlertIncident;
use App\Kernel;
use App\Repository\AlertEvaluationProgressRepository;
use App\Repository\AlertIncidentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';
const ALERT_TYPE = 'replay_test';

final class ReplayProtectionKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-replay-protection/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-replay-protection/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->setAlias('test.messenger_serializer', 'messenger.default_serializer')->setPublic(true);
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

/** Asserts the concurrency-critical schema guards survived the migrations. */
function assertAlertSchemaGuards(Connection $connection): void
{
    $isOpen = $connection->fetchOne(
        "SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'alert_incident' AND COLUMN_NAME = 'is_open'",
        [TEST_DATABASE],
    );
    check(is_string($isOpen) && str_contains(strtolower($isOpen), 'generated'), 'alert_incident.is_open must exist as a database-generated column.');

    $index = $connection->fetchOne(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'alert_incident' AND INDEX_NAME = 'uniq_alert_incident_open' AND NON_UNIQUE = 0",
        [TEST_DATABASE],
    );
    check(0 < (int) $index, 'The uniq_alert_incident_open unique index must exist.');

    foreach (['alert_evaluation_progress', 'alert_processed_uplink'] as $table) {
        $exists = $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [TEST_DATABASE, $table],
        );
        check(1 === (int) $exists, sprintf('Table %s must exist.', $table));
    }
}

/** @return list<SendTelegramNotification> */
function notificationsFor(Connection $connection, SerializerInterface $serializer, string $subjectKey): array
{
    $rows = $connection->fetchAllAssociative(
        "SELECT body, headers FROM messenger_messages WHERE queue_name = 'telegram_notifications' ORDER BY id"
    );

    $messages = [];
    foreach ($rows as $row) {
        $envelope = $serializer->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR)]);
        $message = $envelope->getMessage();
        if ($message instanceof SendTelegramNotification && $subjectKey === $message->subjectKey) {
            $messages[] = $message;
        }
    }

    return $messages;
}

function incidentCount(Connection $connection, string $subjectKey): int
{
    return (int) $connection->fetchOne(
        'SELECT COUNT(*) FROM alert_incident WHERE alert_type = ? AND subject_key = ?',
        [ALERT_TYPE, $subjectKey],
    );
}

function openIncidentCount(Connection $connection, string $subjectKey): int
{
    return (int) $connection->fetchOne(
        "SELECT COUNT(*) FROM alert_incident WHERE alert_type = ? AND subject_key = ? AND status IN ('pending', 'active')",
        [ALERT_TYPE, $subjectKey],
    );
}

/** @return array{AlertLifecycleService, AlertIncidentRepository} */
function service(EntityManagerInterface $em, MessageBusInterface $bus, MockClock $clock): array
{
    /** @var AlertIncidentRepository $repository */
    $repository = $em->getRepository(AlertIncident::class);
    /** @var AlertEvaluationProgressRepository $progressRepository */
    $progressRepository = $em->getRepository(App\Entity\AlertEvaluationProgress::class);

    return [new AlertLifecycleService($repository, $progressRepository, $em, $bus, $clock, reminderIntervalSeconds: 3600), $repository];
}

function scenarioReplayAfterResolution(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-replay-after-resolution';

    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 10.0, unit: '%', measuredAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'), measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    check(1 === openIncidentCount($connection, $subjectKey), 'The first breach must open an incident.');

    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T00:05:00Z'), measurementId: 2), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === openIncidentCount($connection, $subjectKey), 'The recovery signal must resolve the incident.');
    check(2 === count(notificationsFor($connection, $serializer, $subjectKey)), 'Opened + resolved notifications must exist so far.');

    // Replay of the old breach: progress survived resolution, so this is a no-op.
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 10.0, unit: '%', measuredAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'), measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === openIncidentCount($connection, $subjectKey), 'Replaying the old breach must not open a new incident.');
    check(1 === incidentCount($connection, $subjectKey), 'No new incident row may be created by the replay.');
    check(2 === count(notificationsFor($connection, $serializer, $subjectKey)), 'The replay must not enqueue a notification.');

    echo "PASS replay-after-resolution: an old breach replayed after resolution creates no new incident or notification\n";
}

function scenarioOlderReadingAfterHealthy(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-older-after-healthy';

    // A healthy reading (no open incident) still advances the durable watermark.
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T00:50:00Z'), measurementId: 50), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === incidentCount($connection, $subjectKey), 'A healthy reading without an open incident must not create one.');

    // A breach that was measured EARLIER but ingested later (higher id): the
    // watermark (00:50) rejects it even though id 51 > 50.
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 10.0, unit: '%', measuredAt: new \DateTimeImmutable('2026-01-01T00:49:00Z'), measurementId: 51), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === incidentCount($connection, $subjectKey), 'An older-measured breach ingested after a newer healthy reading must not open an incident.');
    check([] === notificationsFor($connection, $serializer, $subjectKey), 'The stale breach must never notify.');

    echo "PASS older-reading-after-healthy: the measured_at watermark rejects a higher-id but older-measured breach\n";
}

function scenarioEqualTimestampConsidered(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-equal-timestamp';

    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T01:00:00Z'), measurementId: 60), confirmationThreshold: 2, recoveryThreshold: 1);
    // Same measured_at, new id: considered (same-timestamp readings apply in
    // arrival order); the watermark does not block it.
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T01:00:00Z'), measurementId: 61), confirmationThreshold: 2, recoveryThreshold: 1);
    check(1 === openIncidentCount($connection, $subjectKey), 'An equal-timestamp reading with a new id must be considered.');
    $status = $connection->fetchOne('SELECT status FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(AlertIncident::STATUS_ACTIVE === $status, 'The equal-timestamp second signal must reach the confirmation threshold.');

    echo "PASS equal-timestamp-considered: readings sharing the watermark measured_at apply in arrival order\n";
}

function scenarioDuplicateEvaluation(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-duplicate-evaluation';

    $signal = new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T02:00:00Z'), measurementId: 70);
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, $signal, confirmationThreshold: 2, recoveryThreshold: 1);
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, $signal, confirmationThreshold: 2, recoveryThreshold: 1);
    $count = (int) $connection->fetchOne('SELECT confirmation_count FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(1 === $count, 'A duplicate evaluation must not increment the confirmation count.');

    // Resolve, then duplicate the resolution recovery signal: still no effect.
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T02:05:00Z'), measurementId: 71), confirmationThreshold: 2, recoveryThreshold: 1);
    check(0 === openIncidentCount($connection, $subjectKey), 'The recovery signal must resolve the pending incident.');
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T02:05:00Z'), measurementId: 71), confirmationThreshold: 2, recoveryThreshold: 1);
    check(0 === openIncidentCount($connection, $subjectKey), 'A duplicate recovery signal after resolution must not reopen anything.');
    check([] === notificationsFor($connection, $serializer, $subjectKey), 'No notification must ever be enqueued for this subject.');

    echo "PASS duplicate-evaluation: the same signal never increments counters, before or after resolution\n";
}

function scenarioNewerBreachAfterResolution(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-newer-after-resolution';

    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T03:00:00Z'), measurementId: 80), confirmationThreshold: 1, recoveryThreshold: 1);
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T03:05:00Z'), measurementId: 81), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === openIncidentCount($connection, $subjectKey), 'The incident must be resolved before the newer breach arrives.');

    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 8.0, unit: '%', measuredAt: new \DateTimeImmutable('2026-01-01T03:10:00Z'), measurementId: 82), confirmationThreshold: 1, recoveryThreshold: 1);
    check(1 === openIncidentCount($connection, $subjectKey), 'A genuinely newer breach must open a fresh incident.');
    check(2 === incidentCount($connection, $subjectKey), 'The fresh breach must create a second incident row.');
    $notifications = notificationsFor($connection, $serializer, $subjectKey);
    check(3 === count($notifications) && 'opened' === $notifications[2]->event, 'The fresh incident must enqueue a new "opened" notification.');

    echo "PASS newer-breach-after-resolution: a newer breach opens a fresh incident with its own notification\n";
}

function scenarioTransactionFailureAndRetry(EntityManagerInterface $em, MessageBusInterface $bus, Connection $connection, SerializerInterface $serializer): void
{
    $clock = new MockClock('2026-01-01T00:00:00Z');
    [$lifecycle] = service($em, $bus, $clock);
    $subjectKey = 'subject-failure-retry';

    // First signal establishes the progress row (committed).
    $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T04:00:00Z'), measurementId: 90), confirmationThreshold: 5, recoveryThreshold: 1);

    // A competing transaction holds the progress row lock; our next signal
    // must fail with a lock-wait timeout (bounded retry semantics), leaving
    // no partial effect behind.
    $dbParts = parse_url((string) getenv('DATABASE_URL'));
    $writer = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbParts['host'], $dbParts['port'] ?? 3306, ltrim($dbParts['path'], '/')), $dbParts['user'] ?? 'root', $dbParts['pass'] ?? '');
    $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $writer->beginTransaction();
    $writer->prepare('SELECT id FROM alert_evaluation_progress WHERE alert_type = ? AND subject_key = ? FOR UPDATE')->execute([ALERT_TYPE, $subjectKey]);

    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
    $failed = false;
    try {
        $lifecycle->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T04:05:00Z'), measurementId: 91), confirmationThreshold: 5, recoveryThreshold: 1);
    } catch (\Throwable) {
        $failed = true;
    }
    $writer->rollBack();
    check($failed, 'The signal must fail while the progress row lock is held elsewhere.');
    check(1 === (int) $connection->fetchOne('SELECT confirmation_count FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]), 'The failed signal must not have changed the incident.');

    // The EntityManager is closed after the failed wrapInTransaction; rebuild
    // from a fresh manager, then retry: exactly one committed effect.
    /** @var \Doctrine\Persistence\ManagerRegistry $registry */
    $registry = $GLOBALS['test.registry'];
    $em2 = $registry->resetManager();
    [$lifecycle2] = service($em2, $bus, $clock);
    $lifecycle2->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T04:05:00Z'), measurementId: 91), confirmationThreshold: 5, recoveryThreshold: 1);
    $count = (int) $em2->getConnection()->fetchOne('SELECT confirmation_count FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(2 === $count, 'The retried signal must be applied exactly once on top of the committed state.');
    $progressId = (int) $em2->getConnection()->fetchOne('SELECT last_considered_measurement_id FROM alert_evaluation_progress WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(91 === $progressId, 'The retried signal must advance the progress row exactly once.');
    check([] === notificationsFor($em2->getConnection(), $serializer, $subjectKey), 'The pending incident must not notify on retry.');

    echo "PASS transaction-failure-retry: a lock-wait failure leaves no partial effect and the retry applies exactly once\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new ReplayProtectionKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);

    $application = new Application($kernel);
    $application->setAutoExit(false);
    runMigrations($application);
    // The migration's transactional DDL implicitly commits underneath DBAL,
    // leaving the shared connection's transaction-nesting tracking
    // inconsistent. Reconnect before using it for the actual scenarios.
    $connection->close();
    assertAlertSchemaGuards($connection);

    $GLOBALS['test.registry'] = $container->get('doctrine');
    $messageBus = $container->get('test.messenger.default_bus');
    $serializer = $container->get('test.messenger_serializer');

    scenarioReplayAfterResolution($entityManager, $messageBus, $connection, $serializer);
    scenarioOlderReadingAfterHealthy($entityManager, $messageBus, $connection, $serializer);
    scenarioEqualTimestampConsidered($entityManager, $messageBus, $connection);
    scenarioDuplicateEvaluation($entityManager, $messageBus, $connection, $serializer);
    scenarioNewerBreachAfterResolution($entityManager, $messageBus, $connection, $serializer);
    scenarioTransactionFailureAndRetry($entityManager, $messageBus, $connection, $serializer);

    echo "PASS replay protection: durable progress survives resolution, orders by measured_at, and is retry-safe\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
