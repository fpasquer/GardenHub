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
 *  2. AlertIncidentRepository::lockOpenIncident()'s own `SELECT ... FOR
 *     UPDATE` genuinely blocks a concurrent openOrAdvance() call for an
 *     already-open incident until the holder commits.
 *
 * Both use a second raw PDO connection in this same process (not a
 * subprocess): simpler than tests/sensor-identity/concurrency.php's
 * subprocess+performance_schema technique, and sufficient here because
 * nothing needs to observe MySQL's own lock-wait metadata mid-flight.
 */

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Entity\AlertIncident;
use App\Kernel;
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

require '/app/vendor/autoload.php';

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
    $service = new AlertLifecycleService($repository, $em, $bus, new MockClock('2026-01-01T00:00:00Z'), reminderIntervalSeconds: 3600);

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

    echo "PASS alert concurrency: unique-index backstop and lockOpenIncident() FOR UPDATE both genuinely serialize concurrent writers\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
