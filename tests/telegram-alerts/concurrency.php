<?php

declare(strict_types=1);

/*
 * Genuine-concurrency regressions for AlertLifecycleService, proving the
 * database-level backstops actually serialize concurrent writers on
 * independent connections (not just the advisory findOpenIncident() read):
 *
 *  1. alert_incident's unique index on (alert_type, subject_key, is_open)
 *     (a MySQL-generated column, only created by the real migration - see
 *     Version20260921120000) blocks a concurrent first-incident INSERT for
 *     the same key, in both writer-commits and writer-rolls-back orders.
 *  2. AlertIncidentRepository::lockOpenIncident()'s own locking refresh
 *     (SELECT ... FOR UPDATE with hydration) genuinely blocks a concurrent
 *     openOrAdvance() call for an already-open incident until the holder
 *     commits.
 *  3. Resolution landing between the advisory read and the lock: a writer
 *     resolves an already-open incident while the main connection still
 *     holds a pre-resolution REPEATABLE-READ snapshot; openOrAdvance() must
 *     see the committed resolution under the lock and create a NEW incident
 *     instead of advancing the resolved row.
 *  4. The mirror image of (3) for recovery: another transaction OPENS an
 *     incident invisible to the main connection's pinned snapshot;
 *     lockOpenIncident() must still find and recover it under the lock
 *     instead of silently no-op'ing on a phantom "nothing open" read.
 *  5. A reminder pass whose findDueForReminder() scan correctly finds an
 *     incident due, but which is then resolved by another transaction
 *     before the per-incident locking refresh() re-checks it, must not send
 *     a stale reminder - exercised via a DBAL driver middleware that injects
 *     the resolution deterministically between those two reads.
 *
 * All use a second raw PDO connection in this same process (not a
 * subprocess): simpler than tests/sensor-identity/concurrency.php's
 * subprocess+performance_schema technique, and sufficient here because
 * nothing needs to observe MySQL's own lock-wait metadata mid-flight.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Alert\Message\EvaluateAlertsHandler;
use App\Alert\Message\EvaluateAlertsMessage;
use App\Entity\AlertEvaluationProgress;
use App\Entity\AlertIncident;
use App\Kernel;
use App\Repository\AlertEvaluationProgressRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\TelegramAlerts\ReminderCollisionConfig;
use Tests\TelegramAlerts\ReminderCollisionMiddleware;

require '/app/vendor/autoload.php';

// Test-only classes under /tests/src are not in the Composer autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\TelegramAlerts\\';
    if (str_starts_with($class, $prefix)) {
        require '/tests/src/'.substr($class, strlen($prefix)).'.php';
    }
});

const TEST_DATABASE = 'telegram_alerts';
const ALERT_TYPE = 'concurrency_test';

final class AlertConcurrencyKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-alert-concurrency/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-alert-concurrency/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->register('test.reminder_collision_middleware', ReminderCollisionMiddleware::class)
            ->addTag('doctrine.middleware');
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

/** @return array{0: string, 1: string, 2: string} [dsn, user, password] */
function pdoParts(): array
{
    $dbParts = parse_url((string) getenv('DATABASE_URL'));

    return [
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbParts['host'], $dbParts['port'] ?? 3306, ltrim($dbParts['path'], '/')),
        $dbParts['user'] ?? 'root',
        $dbParts['pass'] ?? '',
    ];
}

/** Walks the exception chain for a genuine InnoDB lock-wait timeout. */
function isLockWaitTimeout(\Throwable $throwable): bool
{
    while (null !== $throwable) {
        if ($throwable instanceof LockWaitTimeoutException) {
            return true;
        }
        if ($throwable instanceof \Doctrine\DBAL\Driver\Exception && 1205 === $throwable->getCode()) {
            return true;
        }
        $throwable = $throwable->getPrevious();
    }

    return false;
}

function newWriter(): \PDO
{
    [$dsn, $user, $pass] = pdoParts();
    $writer = new \PDO($dsn, $user, $pass);
    $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $writer;
}

/**
 * AlertLifecycleService::openOrAdvance() runs inside EntityManager::wrapInTransaction(),
 * which closes the EntityManager on ANY exception (including a caught
 * lock-wait timeout) - not just flush failures. Every retry after a caught
 * exception must therefore rebuild its services from a fresh manager
 * (via ManagerRegistry::resetManager()), never reuse the closed one.
 *
 * @return array{EntityManagerInterface, AlertLifecycleService}
 */
function freshServices(ManagerRegistry $registry, MessageBusInterface $bus): array
{
    /** @var EntityManagerInterface $em */
    $em = $registry->resetManager();
    $repository = $em->getRepository(AlertIncident::class);
    /** @var AlertEvaluationProgressRepository $progressRepository */
    $progressRepository = $em->getRepository(AlertEvaluationProgress::class);
    $service = new AlertLifecycleService($repository, $progressRepository, $em, $bus, new MockClock('2026-01-01T00:00:00Z'), reminderIntervalSeconds: 3600);

    return [$em, $service];
}

function scenarioFirstIncidentRaceWriterCommits(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-race-commit';
    [, $service] = freshServices($registry, $bus);

    $writer = newWriter();
    $writer->beginTransaction();
    $writer->prepare(
        'INSERT INTO alert_incident (alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, 1, 0, NOW(), NOW(), NOW(), NOW())'
    )->execute([ALERT_TYPE, $subjectKey, AlertIncident::STATUS_PENDING]);
    // Deliberately left open/uncommitted: simulates another writer racing to
    // open the same incident concurrently.

    $blocked = false;
    try {
        $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    } catch (\Throwable $exception) {
        check(isLockWaitTimeout($exception), 'The blocked create() must fail on a genuine InnoDB lock-wait timeout, not an unrelated error: '.$exception->getMessage());
        $blocked = true;
    }
    check($blocked, 'A concurrent first-incident insert for the same key must genuinely block on the unique index backstop.');

    $writer->commit();

    [$em, $service] = freshServices($registry, $bus);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 2), confirmationThreshold: 1, recoveryThreshold: 1);
    $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(1 === $count, 'Exactly one incident row must exist once the writer commits and the retry proceeds via confirm().');

    echo "PASS first-incident-race-commit: a concurrent writer's uncommitted insert genuinely blocks create(); once committed, the retry advances the existing incident instead of duplicating it\n";
}

function scenarioFirstIncidentRaceWriterRollsBack(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-race-rollback';
    [, $service] = freshServices($registry, $bus);

    $writer = newWriter();
    $writer->beginTransaction();
    $writer->prepare(
        'INSERT INTO alert_incident (alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, 1, 0, NOW(), NOW(), NOW(), NOW())'
    )->execute([ALERT_TYPE, $subjectKey, AlertIncident::STATUS_PENDING]);

    $blocked = false;
    try {
        $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    } catch (\Throwable $exception) {
        check(isLockWaitTimeout($exception), 'The blocked create() must fail on a genuine InnoDB lock-wait timeout: '.$exception->getMessage());
        $blocked = true;
    }
    check($blocked, 'A concurrent first-incident insert for the same key must genuinely block.');

    $writer->rollBack();

    [$em, $service] = freshServices($registry, $bus);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    $count = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(1 === $count, 'Exactly one incident row (ours) must exist once the contending writer rolls back.');

    echo "PASS first-incident-race-rollback: once the contending writer rolls back, the retry proceeds normally and creates exactly one incident\n";
}

function scenarioAlreadyOpenIncidentRace(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-race-lock-open';
    [$em, $service] = freshServices($registry, $bus);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 1), confirmationThreshold: 5, recoveryThreshold: 1);
    $incidentId = (int) $em->getConnection()->fetchOne('SELECT id FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);

    $writer = newWriter();
    $writer->beginTransaction();
    $writer->prepare('SELECT id FROM alert_incident WHERE id = ? FOR UPDATE')->execute([$incidentId]);
    $writer->prepare('UPDATE alert_incident SET confirmation_count = confirmation_count + 10 WHERE id = ?')->execute([$incidentId]);
    // Deliberately left open/uncommitted: simulates another process (e.g. a
    // concurrent evaluation) that already holds this incident's row lock.

    $blocked = false;
    try {
        $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 2), confirmationThreshold: 5, recoveryThreshold: 1);
    } catch (\Throwable $exception) {
        check(isLockWaitTimeout($exception), 'The blocked lockOpenIncident() must fail on a genuine InnoDB lock-wait timeout: '.$exception->getMessage());
        $blocked = true;
    }
    check($blocked, 'A concurrent request for the same already-open incident must genuinely block on lockOpenIncident()\'s FOR UPDATE read.');

    $writer->commit();

    [$em, $service] = freshServices($registry, $bus);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 2), confirmationThreshold: 5, recoveryThreshold: 1);
    $confirmationCount = (int) $em->getConnection()->fetchOne('SELECT confirmation_count FROM alert_incident WHERE id = ?', [$incidentId]);
    // 1 (initial) + 10 (writer's manual bump) + 1 (our own retried signal).
    check(12 === $confirmationCount, 'The retry must see the writer\'s committed change and add its own increment on top of it. Got: '.$confirmationCount);

    echo "PASS already-open-incident-race: a concurrent FOR UPDATE holder genuinely blocks the retry; once committed, the retry correctly reflects both changes\n";
}

function scenarioResolutionBeforeLock(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-resolution-before-lock';
    [$em, $service] = freshServices($registry, $bus);
    // confirmation_threshold=1: activates (and notifies) on the first breach.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    $incidentId = (int) $em->getConnection()->fetchOne('SELECT id FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);

    // Pin a REPEATABLE-READ snapshot on the main connection that still shows
    // the incident as open.
    $connection = $em->getConnection();
    $connection->beginTransaction();
    $connection->fetchOne('SELECT status FROM alert_incident WHERE id = ?', [$incidentId]);

    // Another transaction resolves the incident and commits: the main
    // connection's snapshot and identity map still say "active".
    $writer = newWriter();
    $writer->beginTransaction();
    $writer->prepare("UPDATE alert_incident SET status = ?, resolved_at = NOW() WHERE id = ?")->execute([AlertIncident::STATUS_RESOLVED, $incidentId]);
    $writer->commit();

    // The open incident row was created in a prior committed transaction, so
    // its snapshot insert was already committed before this transaction
    // started; the same holds for the progress row. openOrAdvance() must
    // therefore reach the incident lock, discover the resolution under the
    // lock, and open a NEW incident for this fresh breach (id 2, newer
    // measured_at) rather than advancing the resolved row.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, measuredAt: new \DateTimeImmutable('2026-01-01T00:10:00Z'), measurementId: 2), confirmationThreshold: 1, recoveryThreshold: 1);
    $connection->commit();

    $rows = $connection->fetchAllAssociative('SELECT id, status, confirmation_count, last_considered_measurement_id FROM alert_incident WHERE alert_type = ? AND subject_key = ? ORDER BY id', [ALERT_TYPE, $subjectKey]);
    check(2 === count($rows), 'A new incident must be created for the fresh breach instead of advancing the resolved row. Got: '.json_encode($rows));

    $resolved = $rows[0];
    check((int) $resolved['id'] === $incidentId && AlertIncident::STATUS_RESOLVED === $resolved['status'], 'The original incident must remain resolved.');
    check(1 === (int) $resolved['confirmation_count'] && 1 === (int) $resolved['last_considered_measurement_id'], 'The resolved row must be untouched by the fresh signal. Got: '.json_encode($resolved));

    $new = $rows[1];
    check(AlertIncident::STATUS_ACTIVE === $new['status'], 'The fresh breach must create an ACTIVE incident (threshold 1).');
    check(2 === (int) $new['last_considered_measurement_id'], 'The new incident must carry the fresh signal\'s measurement id.');

    $notifications = (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'telegram_notifications' AND body LIKE '%subject-resolution-before-lock%'");
    check(2 === $notifications, 'Exactly two notifications (one per opened incident) must exist for this subject.');

    echo "PASS resolution-before-lock: resolution landing between the advisory read and the lock yields a new incident; the resolved row stays untouched\n";
}

function scenarioRecoveryMissesPhantomIncident(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-recovery-phantom-incident';
    [$em, $service] = freshServices($registry, $bus);
    // A recovery signal with no incident open yet only creates the progress
    // row (openOrAdvance() no-ops on a non-breach signal when nothing is
    // open) - the "bare progress row, no incident" precondition.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    check(0 === (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]), 'No incident may exist yet.');

    // Pin a REPEATABLE-READ snapshot on the main connection before any
    // incident for this subject exists.
    $connection = $em->getConnection();
    $connection->beginTransaction();
    $connection->fetchOne('SELECT id FROM alert_evaluation_progress WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);

    // Another transaction opens and commits an incident for the same key:
    // invisible to the main connection's pinned snapshot.
    $writer = newWriter();
    $writer->beginTransaction();
    $writer->prepare(
        'INSERT INTO alert_incident (alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, last_considered_measurement_id, last_measured_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, 1, 0, NOW(), NOW(), 2, NOW(), NOW(), NOW())'
    )->execute([ALERT_TYPE, $subjectKey, AlertIncident::STATUS_ACTIVE]);
    $writer->commit();

    // A recovery signal processed inside the still-open outer transaction
    // must still find and recover this incident: lockOpenIncident() must not
    // rely on the advisory snapshot, which shows nothing here.
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T00:05:00Z'), measurementId: 3), confirmationThreshold: 1, recoveryThreshold: 1);
    $connection->commit();

    $row = $connection->fetchAssociative('SELECT status, recovery_count, resolved_at FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(1 === (int) $row['recovery_count'], 'The phantom incident must be recovered despite the stale snapshot. Got: '.json_encode($row));
    check(AlertIncident::STATUS_RESOLVED === $row['status'] && null !== $row['resolved_at'], 'A recovery_threshold of 1 must resolve the incident. Got: '.json_encode($row));

    // Replaying the same signal must be a no-op (replay protection via the
    // progress watermark).
    [$em2, $service2] = freshServices($registry, $bus);
    $service2->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: false, measuredAt: new \DateTimeImmutable('2026-01-01T00:05:00Z'), measurementId: 3), confirmationThreshold: 1, recoveryThreshold: 1);
    $replayed = $em2->getConnection()->fetchAssociative('SELECT recovery_count FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);
    check(1 === (int) $replayed['recovery_count'], 'Replaying the same recovery signal must not change the incident again. Got: '.json_encode($replayed));

    echo "PASS recovery-misses-phantom-incident: a recovery signal correctly recovers an incident opened by another transaction after this transaction's snapshot was pinned\n";
}

function scenarioReminderAfterConcurrentResolution(ManagerRegistry $registry, MessageBusInterface $bus): void
{
    $subjectKey = 'subject-reminder-resolution-race';
    [$em, $service] = freshServices($registry, $bus);
    $service->openOrAdvance(ALERT_TYPE, $subjectKey, new AlertSignal(breach: true, value: 5.0, unit: '%', measurementId: 1), confirmationThreshold: 1, recoveryThreshold: 1);
    $incidentId = (int) $em->getConnection()->fetchOne('SELECT id FROM alert_incident WHERE alert_type = ? AND subject_key = ?', [ALERT_TYPE, $subjectKey]);

    $connection = $em->getConnection();
    $connection->executeStatement('UPDATE alert_incident SET next_reminder_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $incidentId]);

    [$dsn, $user, $pass] = pdoParts();
    ReminderCollisionMiddleware::arm(new ReminderCollisionConfig($incidentId, $dsn, $user, $pass));

    $repository = $em->getRepository(AlertIncident::class);
    $handler = new EvaluateAlertsHandler($repository, $em, $bus, new MockClock('2026-01-01T01:00:00Z'), reminderIntervalSeconds: 3600);
    // The middleware resolves the incident right after this handler's own
    // findDueForReminder() SELECT returns it as due, but before its
    // per-incident locking refresh() re-checks it under FOR UPDATE.
    $handler(new EvaluateAlertsMessage());
    ReminderCollisionMiddleware::disarm();

    $reminders = (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'telegram_notifications' AND body LIKE '%reminder%' AND body LIKE '%subject-reminder-resolution-race%'");
    check(0 === $reminders, 'No reminder may be enqueued once the incident was resolved concurrently.');
    $row = $connection->fetchAssociative('SELECT status, next_reminder_at, last_reminder_at FROM alert_incident WHERE id = ?', [$incidentId]);
    check(AlertIncident::STATUS_RESOLVED === $row['status'] && null === $row['last_reminder_at'], 'The resolved row must not be mutated by the reminder pass. Got: '.json_encode($row));
    check('2020-01-01 00:00:00' === $row['next_reminder_at'], 'The resolved row\'s next_reminder_at must be untouched.');

    echo "PASS reminder-after-resolution: a concurrent resolution landing between findDueForReminder() and the locking refresh() is caught; no stale reminder is sent\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new AlertConcurrencyKernel('dev', true);
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

    // Bounds this connection's own lock waits so a genuinely contended
    // operation fails fast (proving blocking) instead of hanging.
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');

    $registry = $container->get('doctrine');
    $messageBus = $container->get('test.messenger.default_bus');

    scenarioFirstIncidentRaceWriterCommits($registry, $messageBus);
    scenarioFirstIncidentRaceWriterRollsBack($registry, $messageBus);
    scenarioAlreadyOpenIncidentRace($registry, $messageBus);
    scenarioResolutionBeforeLock($registry, $messageBus);
    scenarioRecoveryMissesPhantomIncident($registry, $messageBus);
    scenarioReminderAfterConcurrentResolution($registry, $messageBus);

    echo "PASS alert concurrency: unique-index backstop, locking refresh, resolution-under-lock and reminder recheck all genuinely serialize concurrent writers\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
