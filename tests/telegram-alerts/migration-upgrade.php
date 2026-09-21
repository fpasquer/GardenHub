<?php

declare(strict_types=1);

/*
 * Integration test for PR #10 review finding 3: proves the split migration
 * (Version20260922000000's schema/processed-uplink backfill, followed by
 * Version20260923000000's deterministic alert_evaluation_progress backfill)
 * upgrades a REAL pre-fix database correctly in both directions that
 * matter:
 *
 *  - A fresh install with tied historical alert_incident rows (same
 *    (alert_type, subject_key) and the same max last_considered_measurement_id)
 *    must not crash, and must pick exactly one coherent (measurement_id,
 *    measured_at) pair via the highest-measurement-id-then-highest-incident-id
 *    tie-breaker.
 *  - An installation where the old backfill already produced a progress row
 *    and live processing has since advanced it further must never have that
 *    row regressed by the new migration's backfill.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'telegram_alerts';
const PRE_INCIDENT_VERSIONS = [
    'Version20260824163627',
    'Version20260824192259',
    'Version20260908195213',
    'Version20260918120000',
    'Version20260919151830',
    'Version20260921120000',
];

final class MigrationUpgradeKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-migration-upgrade/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-migration-upgrade/log';
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

function runMigration(Application $application, string $version): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:execute',
        'versions' => ['DoctrineMigrations\\'.$version],
        '--up' => true,
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, "Migration $version failed: ".$output->fetch());
}

function runRemainingMigrations(Application $application): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, 'Migrating the rest of the chain failed: '.$output->fetch());
}

function seedMeasurementsForProcessedUplinkBackfill(Connection $connection): void
{
    $connection->executeStatement("INSERT INTO device (id, name, dev_eui, created_at) VALUES (1, 'Upgrade device', 'migration-upgrade-eui', NOW())");
    $connection->executeStatement("INSERT INTO sensor (id, device_id, type, unit, label, created_at) VALUES (1, 1, 'soil_moisture', '%', 'water_SOIL', NOW())");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (1, 1, 12.0, '2026-01-01 09:00:00', NOW(), 'dedup-upgrade-a', 'soil_moisture')");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (2, 1, 13.0, '2026-01-01 09:30:00', NOW(), 'dedup-upgrade-b', 'soil_moisture')");
}

function seedTiedIncidents(Connection $connection): void
{
    // Same (alert_type, subject_key) and the same max last_considered_measurement_id
    // (50): the exact tie that made Version20260922000000's original backfill
    // emit two rows for one target unique key.
    $connection->executeStatement(<<<'SQL'
        INSERT INTO alert_incident
            (id, alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, last_considered_measurement_id, last_measured_at, resolved_at, created_at, updated_at)
        VALUES (101, 'migration_test', 'subject-tie', 'resolved', 3, 1, '2026-01-01 10:00:00', '2026-01-01 10:00:00', 50, '2026-01-01 10:00:00', '2026-01-01 10:00:00', NOW(), NOW())
        SQL);
    $connection->executeStatement(<<<'SQL'
        INSERT INTO alert_incident
            (id, alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, last_considered_measurement_id, last_measured_at, created_at, updated_at)
        VALUES (102, 'migration_test', 'subject-tie', 'active', 1, 0, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 50, '2026-01-01 11:00:00', NOW(), NOW())
        SQL);
}

function assertProcessedUplinkBackfill(Connection $connection): void
{
    $rows = $connection->fetchAllAssociative('SELECT deduplication_id, first_processed_at FROM alert_processed_uplink ORDER BY deduplication_id');
    check(2 === count($rows), 'Both historical measurement dedup ids must be backfilled into alert_processed_uplink. Got: '.json_encode($rows));
    check('dedup-upgrade-a' === $rows[0]['deduplication_id'] && 'dedup-upgrade-b' === $rows[1]['deduplication_id'], 'The backfilled dedup ids must match the seeded measurements.');
}

function assertIsOpenConstraintStillEnforced(Connection $connection): void
{
    // subject-tie already has an open (active) incident (id 102): a second
    // open row for the same key must still collide on uniq_alert_incident_open.
    try {
        $connection->executeStatement(<<<'SQL'
            INSERT INTO alert_incident
                (alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, created_at, updated_at)
            VALUES ('migration_test', 'subject-tie', 'pending', 1, 0, NOW(), NOW(), NOW(), NOW())
            SQL);
    } catch (UniqueConstraintViolationException) {
        return;
    }
    throw new RuntimeException('A second open incident for the same (alert_type, subject_key) must still violate uniq_alert_incident_open after the upgrade.');
}

function scenarioFreshInstallTiedIncidents(Connection $connection, Application $application): void
{
    resetDatabase($connection);
    foreach (PRE_INCIDENT_VERSIONS as $version) {
        runMigration($application, $version);
    }

    seedMeasurementsForProcessedUplinkBackfill($connection);
    seedTiedIncidents($connection);

    runRemainingMigrations($application);
    // Migration DDL implicitly commits underneath DBAL, leaving the shared
    // connection's transaction tracking stale; reconnect before reuse.
    $connection->close();

    $progressRows = $connection->fetchAllAssociative("SELECT last_considered_measurement_id, last_considered_measured_at FROM alert_evaluation_progress WHERE alert_type = 'migration_test' AND subject_key = 'subject-tie'");
    check(1 === count($progressRows), 'Exactly one progress row must exist for the tied subject. Got: '.json_encode($progressRows));
    check(50 === (int) $progressRows[0]['last_considered_measurement_id'], 'The tied measurement id must be preserved.');
    check('2026-01-01 11:00:00' === $progressRows[0]['last_considered_measured_at'], 'The tie-breaker must pick the highest incident id (102, measured_at 11:00:00), not the lower one (101, 10:00:00). Got: '.json_encode($progressRows[0]));

    $incidents = $connection->fetchAllAssociative("SELECT id, status, confirmation_count, recovery_count FROM alert_incident WHERE alert_type = 'migration_test' AND subject_key = 'subject-tie' ORDER BY id");
    check([
        ['id' => 101, 'status' => 'resolved', 'confirmation_count' => 3, 'recovery_count' => 1],
        ['id' => 102, 'status' => 'active', 'confirmation_count' => 1, 'recovery_count' => 0],
    ] === array_map(static fn (array $row): array => [
        'id' => (int) $row['id'], 'status' => $row['status'], 'confirmation_count' => (int) $row['confirmation_count'], 'recovery_count' => (int) $row['recovery_count'],
    ], $incidents), 'Both tied incident rows must survive the backfill untouched. Got: '.json_encode($incidents));

    assertProcessedUplinkBackfill($connection);
    assertIsOpenConstraintStillEnforced($connection);

    echo "PASS fresh-install-tied-incidents: tied historical incidents backfill deterministically (highest measurement id, then highest incident id); constraints intact\n";
}

function scenarioLiveProgressNeverRegresses(Connection $connection, Application $application): void
{
    resetDatabase($connection);
    foreach ([...PRE_INCIDENT_VERSIONS, 'Version20260922000000'] as $version) {
        runMigration($application, $version);
    }

    // Untied historical data: a single incident with a lower watermark than
    // what live processing will advance to below.
    $connection->executeStatement(<<<'SQL'
        INSERT INTO alert_incident
            (alert_type, subject_key, status, confirmation_count, recovery_count, first_detected_at, last_evaluated_at, last_considered_measurement_id, last_measured_at, resolved_at, created_at, updated_at)
        VALUES ('migration_test', 'subject-live', 'resolved', 1, 1, '2026-01-01 08:00:00', '2026-01-01 08:00:00', 10, '2026-01-01 08:00:00', '2026-01-01 08:00:00', NOW(), NOW())
        SQL);

    // Stand in for "the old (pre-fix) backfill already ran and produced this
    // row" - Version20260922000000 no longer inserts it, so it is seeded
    // directly here to simulate that historical state.
    $connection->executeStatement(<<<'SQL'
        INSERT INTO alert_evaluation_progress
            (alert_type, subject_key, last_considered_measurement_id, last_considered_measured_at, created_at, updated_at)
        VALUES ('migration_test', 'subject-live', 10, '2026-01-01 08:00:00', NOW(), NOW())
        SQL);

    // Live processing has since advanced progress well past the historical
    // incident watermark - this must never be regressed by the new backfill.
    $connection->executeStatement("UPDATE alert_evaluation_progress SET last_considered_measurement_id = 99, last_considered_measured_at = '2026-01-01 12:00:00', updated_at = NOW() WHERE alert_type = 'migration_test' AND subject_key = 'subject-live'");

    runMigration($application, 'Version20260923000000');
    $connection->close();

    $row = $connection->fetchAssociative("SELECT last_considered_measurement_id, last_considered_measured_at FROM alert_evaluation_progress WHERE alert_type = 'migration_test' AND subject_key = 'subject-live'");
    check(99 === (int) $row['last_considered_measurement_id'] && '2026-01-01 12:00:00' === $row['last_considered_measured_at'], 'Live-advanced progress must never be regressed by the historical backfill. Got: '.json_encode($row));

    echo "PASS live-progress-never-regresses: progress already advanced past the historical watermark by live processing is left untouched\n";
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');

    $kernel = new MigrationUpgradeKernel('dev', true);
    $kernel->boot();
    $connection = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
    check(TEST_DATABASE === $connection->getDatabase(), 'Refusing to modify a non-test database.');
    $application = new Application($kernel);
    $application->setAutoExit(false);

    scenarioFreshInstallTiedIncidents($connection, $application);

    // TableMetadataStorage caches an "already initialized" flag for the
    // lifetime of the container's DependencyFactory singleton, so reusing
    // the same kernel after resetDatabase() drops doctrine_migration_versions
    // again would skip recreating it. Reboot for a fresh container.
    $kernel->shutdown();
    $kernel->boot();
    $connection = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
    $application = new Application($kernel);
    $application->setAutoExit(false);

    scenarioLiveProgressNeverRegresses($connection, $application);

    echo "PASS migration upgrade: tied historical backfill is deterministic and live-advanced progress is never regressed\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}

