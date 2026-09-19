<?php

declare(strict_types=1);

/*
 * Integration test for PR #6: proves the two new migrations
 * (Version20260918120000, Version20260919151830) correctly upgrade a REAL
 * pre-PR database built via Doctrine Migrations (never SchemaTool/entity
 * metadata), that the new unique constraints are enforced, and that a fresh
 * install applies the complete chain cleanly.
 *
 * Runs last in the entrypoint chain: it is the only script that fully wipes
 * the schema via raw DROP TABLE, so nothing else may depend on its end state.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\DependencyFactory;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';

const TEST_DATABASE = 'worker_lifecycle';

final class MigrationKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-migration/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-migration/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Not public by default; needed for the pending-migrations metadata check.
        $container->setAlias('test.migrations_dependency_factory', 'doctrine.migrations.dependency_factory')->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Drops every table in the test database via raw SQL. The migrations
 * themselves stay this file's only source of schema truth.
 */
function resetDatabase(Connection $connection): void
{
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Refusing to reset schema outside the isolated test Compose file.');
    $database = $connection->getDatabase();
    check(TEST_DATABASE === $database, 'Refusing to reset a non-test database.');

    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $tables = $connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database],
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

function runFullMigrationChain(Application $application, string $failureMessage): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, $failureMessage.': '.$output->fetch());
}

/** Asserts $insert throws exactly Doctrine's unique-constraint violation. */
function expectUniqueViolation(Closure $insert, string $message): void
{
    try {
        $insert();
    } catch (UniqueConstraintViolationException) {
        return;
    }
    throw new RuntimeException($message);
}

function seedLegacyFixture(Connection $connection): void
{
    // Old schema: measurement has no deduplication_id/type yet; device.dev_eui
    // and sensor(device_id, type) are not yet unique.
    $connection->executeStatement("INSERT INTO device (id, name, dev_eui, created_at) VALUES (1, 'Upgrade device A', 'upgrade-eui-aaa', NOW())");
    $connection->executeStatement("INSERT INTO device (id, name, dev_eui, created_at) VALUES (2, 'Upgrade device B', 'upgrade-eui-bbb', NOW())");
    $connection->executeStatement("INSERT INTO sensor (id, device_id, type, unit, label, created_at) VALUES (1, 1, 'soil_temperature', '°C', 'temp_SOIL', NOW())");
    $connection->executeStatement("INSERT INTO sensor (id, device_id, type, unit, label, created_at) VALUES (2, 1, 'battery', 'V', 'BatV', NOW())");
    $connection->executeStatement("INSERT INTO sensor (id, device_id, type, unit, label, created_at) VALUES (3, 2, 'soil_temperature', '°C', 'temp_SOIL', NOW())");
    // Historical duplicate: same sensor + same measured_at, different value.
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (1, 1, 18.5, '2020-01-01 12:00:00', NOW())");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (2, 1, 18.7, '2020-01-01 12:00:00', NOW())");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (3, 1, 19.0, '2020-01-01 13:00:00', NOW())");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (4, 2, 3.3, '2020-01-01 12:00:00', NOW())");
    $connection->executeStatement("INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (5, 3, 17.0, '2020-01-02 08:00:00', NOW())");
}

/** @return array{measurements: list<array<string, mixed>>, sensors: list<array<string, mixed>>, devices: list<array<string, mixed>>} */
function captureSnapshot(Connection $connection): array
{
    return [
        'measurements' => $connection->fetchAllAssociative('SELECT id, sensor_id, value, measured_at, created_at FROM measurement ORDER BY id'),
        'sensors' => $connection->fetchAllAssociative('SELECT * FROM sensor ORDER BY id'),
        'devices' => $connection->fetchAllAssociative('SELECT * FROM device ORDER BY id'),
    ];
}

function assertSnapshotsMatch(array $before, array $after): void
{
    check($before['devices'] === $after['devices'], 'Device rows must be unchanged by the upgrade.');
    check($before['sensors'] === $after['sensors'], 'Sensor rows must be unchanged by the upgrade.');
    check($before['measurements'] === $after['measurements'], 'Measurement id/sensor_id/value/measured_at/created_at must be unchanged by the upgrade.');
}

/** @return list<array<string, mixed>> */
function assertBackfill(Connection $connection): array
{
    $rows = $connection->fetchAllAssociative('
        SELECT m.id, m.deduplication_id, m.type AS measurement_type, s.type AS sensor_type
        FROM measurement m JOIN sensor s ON s.id = m.sensor_id
        ORDER BY m.id
    ');
    check(5 === count($rows), 'All 5 historical measurements must survive the upgrade.');
    foreach ($rows as $row) {
        check(null !== $row['deduplication_id'] && Uuid::isValid($row['deduplication_id']), "Measurement {$row['id']} must have a valid non-null UUID.");
        check($row['measurement_type'] === $row['sensor_type'], "Measurement {$row['id']} type must match its sensor's type.");
    }

    return $rows;
}

function scenarioUpgrade(Connection $connection, Application $application): void
{
    resetDatabase($connection);
    foreach (['Version20260824163627', 'Version20260824192259', 'Version20260908195213'] as $version) {
        runMigration($application, $version);
    }

    seedLegacyFixture($connection);
    $before = captureSnapshot($connection);

    runMigration($application, 'Version20260918120000');
    runMigration($application, 'Version20260919151830');
    // Migration DDL implicitly commits underneath DBAL, leaving the shared
    // connection's transaction tracking stale; reconnect before reuse.
    $connection->close();

    assertSnapshotsMatch($before, captureSnapshot($connection));
    $backfilled = assertBackfill($connection);
    check($backfilled[0]['deduplication_id'] !== $backfilled[1]['deduplication_id'], 'Historical duplicate measurements (same sensor+timestamp) must receive distinct UUIDs.');

    $total = (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement');
    $distinct = (int) $connection->fetchOne('SELECT COUNT(DISTINCT deduplication_id) FROM measurement');
    check($total === $distinct, 'The backfill must not produce any UUID collisions.');

    echo "PASS upgrade: pre-PR data preserved, every historical measurement backfilled with a valid UUID and correct type, duplicates kept distinct\n";
}

function assertMeasurementDedupTypeConstraint(Connection $connection, int $humiditySensor, int $batterySensor): void
{
    $dedupId = (string) Uuid::v4();
    $insert = static function (int $sensorId, string $type, float $value) use ($connection, $dedupId): void {
        $connection->executeStatement(
            'INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (?, ?, NOW(), NOW(), ?, ?)',
            [$sensorId, $value, $dedupId, $type],
        );
    };

    $insert($humiditySensor, 'humidity', 55.0);
    expectUniqueViolation(static fn () => $insert($humiditySensor, 'humidity', 60.0), 'Repeating (deduplication_id, type) must be rejected.');
    $insert($batterySensor, 'battery', 3.9);

    check(2 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$dedupId]), 'The same UUID with a different type must be accepted.');
}

function assertDeviceEuiConstraint(Connection $connection): void
{
    expectUniqueViolation(
        static fn () => $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Duplicate eui device', 'upgrade-eui-aaa', NOW())"),
        'Repeating a non-null device EUI must be rejected.',
    );
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Null eui device 1', NULL, NOW())");
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Null eui device 2', NULL, NOW())");
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Distinct eui device', 'constraint-eui-2', NOW())");
}

function assertSensorDeviceTypeConstraint(Connection $connection): void
{
    expectUniqueViolation(
        static fn () => $connection->executeStatement("INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (1, 'soil_temperature', '°C', 'dup', NOW())"),
        'Repeating (device_id, type) must be rejected.',
    );

    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Second temp device', 'constraint-eui-3', NOW())");
    $secondDevice = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$secondDevice, 'soil_temperature', '°C', 'temp_SOIL']);
}

function scenarioConstraints(Connection $connection): void
{
    // Independent fixtures on top of the already-verified upgrade data, so
    // scenario 1's snapshot assertions are never retroactively invalidated.
    $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Constraint device', 'constraint-eui', NOW())");
    $device = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$device, 'humidity', '%', 'hum']);
    $humiditySensor = (int) $connection->lastInsertId();
    $connection->executeStatement('INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())', [$device, 'battery', 'V', 'BatV']);
    $batterySensor = (int) $connection->lastInsertId();

    assertMeasurementDedupTypeConstraint($connection, $humiditySensor, $batterySensor);
    assertDeviceEuiConstraint($connection);
    assertSensorDeviceTypeConstraint($connection);

    echo "PASS constraints: (deduplication_id, type), device.dev_eui and sensor(device_id, type) uniqueness enforced with the expected acceptances\n";
}

/** @return list<array{COLUMN_NAME: string, SEQ_IN_INDEX: int, NON_UNIQUE: int}> */
function indexColumns(Connection $connection, string $table, string $index): array
{
    $rows = $connection->fetchAllAssociative(
        'SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
        [TEST_DATABASE, $table, $index],
    );

    // Normalize PDO's driver-dependent numeric typing before strict comparison.
    return array_map(static fn (array $row): array => [
        'COLUMN_NAME' => (string) $row['COLUMN_NAME'],
        'SEQ_IN_INDEX' => (int) $row['SEQ_IN_INDEX'],
        'NON_UNIQUE' => (int) $row['NON_UNIQUE'],
    ], $rows);
}

function assertNewConstraintsDefined(Connection $connection): void
{
    check([
        ['COLUMN_NAME' => 'deduplication_id', 'SEQ_IN_INDEX' => 1, 'NON_UNIQUE' => 0],
        ['COLUMN_NAME' => 'type', 'SEQ_IN_INDEX' => 2, 'NON_UNIQUE' => 0],
    ] === indexColumns($connection, 'measurement', 'uniq_measurement_dedup_type'), 'uniq_measurement_dedup_type must be a unique index covering (deduplication_id, type) in order.');

    check([
        ['COLUMN_NAME' => 'dev_eui', 'SEQ_IN_INDEX' => 1, 'NON_UNIQUE' => 0],
    ] === indexColumns($connection, 'device', 'UNIQ_92FB68E776A7E7E'), 'UNIQ_92FB68E776A7E7E must be a unique index covering device.dev_eui.');

    check([
        ['COLUMN_NAME' => 'device_id', 'SEQ_IN_INDEX' => 1, 'NON_UNIQUE' => 0],
        ['COLUMN_NAME' => 'type', 'SEQ_IN_INDEX' => 2, 'NON_UNIQUE' => 0],
    ] === indexColumns($connection, 'sensor', 'uniq_sensor_device_type'), 'uniq_sensor_device_type must be a unique index covering (device_id, type) in order.');

    $dedupIdColumn = $connection->fetchAssociative("SHOW COLUMNS FROM measurement LIKE 'deduplication_id'");
    $typeColumn = $connection->fetchAssociative("SHOW COLUMNS FROM measurement LIKE 'type'");
    check('NO' === $dedupIdColumn['Null'], 'measurement.deduplication_id must be NOT NULL after a fresh install.');
    check('NO' === $typeColumn['Null'], 'measurement.type must be NOT NULL after a fresh install.');
}

function scenarioFreshInstall(Connection $connection, Application $application, DependencyFactory $dependencyFactory): void
{
    resetDatabase($connection);

    runFullMigrationChain($application, 'The full migration chain must apply cleanly to an empty database');
    assertNewConstraintsDefined($connection);
    // Necessary but not sufficient: a clean exit alone doesn't prove nothing
    // is pending, hence the metadata-API check below.
    runFullMigrationChain($application, 'A second migrate run must exit successfully (no-op)');

    $pending = count($dependencyFactory->getMigrationStatusCalculator()->getNewMigrations());
    check(0 === $pending, "No pending migrations expected after the full chain, found $pending.");

    echo "PASS fresh-install: complete migration chain applies cleanly, constraints correctly defined, no migrations pending\n";
}

/** @return array{0: Connection, 1: Application, 2: DependencyFactory} */
function bootMigrationServices(MigrationKernel $kernel): array
{
    $container = $kernel->getContainer();

    /** @var Connection $connection */
    $connection = $container->get('doctrine')->getManager()->getConnection();
    check(TEST_DATABASE === $connection->getDatabase(), 'Refusing to modify a non-test database.');

    $application = new Application($kernel);
    $application->setAutoExit(false);
    /** @var DependencyFactory $dependencyFactory */
    $dependencyFactory = $container->get('test.migrations_dependency_factory');

    return [$connection, $application, $dependencyFactory];
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new MigrationKernel('dev', true);
    $kernel->boot();
    [$connection, $application] = bootMigrationServices($kernel);

    scenarioUpgrade($connection, $application);
    scenarioConstraints($connection);

    // TableMetadataStorage caches an "already initialized" flag for the
    // lifetime of the container's DependencyFactory singleton, so reusing
    // the same kernel after resetDatabase() drops doctrine_migration_versions
    // again would skip recreating it. Reboot for a fresh container, exactly
    // like a real fresh-install process would start.
    $kernel->shutdown();
    $kernel->boot();
    [$connection, $application, $dependencyFactory] = bootMigrationServices($kernel);
    scenarioFreshInstall($connection, $application, $dependencyFactory);

    echo "PASS migration integration: upgrade preservation, constraint enforcement, fresh install\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}
