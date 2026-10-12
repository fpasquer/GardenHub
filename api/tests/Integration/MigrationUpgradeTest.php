<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\MigrationFixtures;
use App\Tests\Support\MigrationHarness;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[Group('functional_gardenhub-migration-upgrade')]
final class MigrationUpgradeTest extends TestCase
{
    private MigrationHarness $harness;
    private const IDS = 'Version20260929130000';
    private const PRE_IDS = 'Version20260929120000';

    protected function setUp(): void
    {
        $this->harness = new MigrationHarness();
        $this->harness->reset();
    }

    protected function tearDown(): void
    {
        $this->harness->shutdown();
    }

    public function testHistoricalUpgradePreservesRowsAndBackfillsDistinctUuids(): void
    {
        $this->upgradeLegacy();
    }

    public function testNewConstraintsRejectDuplicatesAndAcceptDistinctKeys(): void
    {
        $this->upgradeLegacy();
        $connection = $this->harness->connection;
        $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Constraint device', 'constraint-eui', NOW())");
        $device = (int) $connection->lastInsertId();
        $sql = 'INSERT INTO sensor (device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, NOW())';
        $connection->executeStatement($sql, [$device, 'humidity', '%', 'hum']);
        $humidity = (int) $connection->lastInsertId();
        $connection->executeStatement($sql, [$device, 'battery', 'V', 'BatV']);
        $battery = (int) $connection->lastInsertId();
        $id = (string) Uuid::v4();
        $insert = static function (int $sensor, string $type, float $value) use ($connection, $id): void {
            $connection->executeStatement('INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (?, ?, NOW(), NOW(), ?, ?)', [$sensor, $value, $id, $type]);
        };
        $insert($humidity, 'humidity', 55.0);
        $this->unique(static fn () => $insert($humidity, 'humidity', 60.0));
        $insert($battery, 'battery', 3.9);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$id]));
        $this->unique(static fn () => $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Duplicate eui device', 'upgrade-eui-aaa', NOW())"));
        foreach ([['Null eui device 1', null], ['Null eui device 2', null], ['Distinct eui device', 'constraint-eui-2']] as $row) {
            $connection->executeStatement('INSERT INTO device (name, dev_eui, created_at) VALUES (?, ?, NOW())', $row);
        }
        $this->unique(static fn () => $connection->executeStatement($sql, [1, 'soil_temperature', "\u{00B0}C", 'dup']));
        $connection->executeStatement("INSERT INTO device (name, dev_eui, created_at) VALUES ('Second temp device', 'constraint-eui-3', NOW())");
        $connection->executeStatement($sql, [(int) $connection->lastInsertId(), 'soil_temperature', "\u{00B0}C", 'temp_SOIL']);
    }

    public function testFreshInstallDefinesIndexesAndHasNoPendingMigrations(): void
    {
        $this->harness->migrate();
        $this->assertIndex('measurement', 'uniq_measurement_dedup_type', ['deduplication_id', 'type'], 0);
        $this->assertIndex('device', 'UNIQ_92FB68E776A7E7E', ['dev_eui'], 0);
        $this->assertIndex('sensor', 'uniq_sensor_device_type', ['device_id', 'type'], 0);
        foreach (['deduplication_id', 'type'] as $column) {
            $definition = $this->harness->connection->fetchAssociative(sprintf("SHOW COLUMNS FROM measurement LIKE '%s'", $column));
            self::assertSame('NO', $definition['Null']);
        }
        $this->harness->migrate();
        self::assertCount(0, $this->harness->factory->getMigrationStatusCalculator()->getNewMigrations());
    }

    public function testWateringIdsPreserveRunsAndLinksAcrossUpDownUp(): void
    {
        $this->prepareWatering(true);
        self::assertSame(4, $this->storedCount('watering_proposal'));
        self::assertSame(1, $this->storedCount('watering_proposal_state'));
        $this->idStep(true);
        $this->assertIntegerLinks();
        $this->assertWateringSchema();
        self::assertSame(3, $this->storedCount('watering_run'));
        $this->assertProposalsDiscarded();
        $connection = $this->harness->connection;
        $connection->executeStatement("INSERT INTO watering_run (requested_seconds, status, requested_at, deadline_at) VALUES (44, 'finished', NOW(), NOW())");
        MigrationFixtures::integerProposals($connection);
        self::assertSame(4, (int) $connection->fetchOne('SELECT MAX(id) FROM watering_run'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT MAX(id) FROM watering_proposal'));
        $connection->executeStatement('DELETE FROM watering_run WHERE requested_seconds = 44');
        $this->idStep(false);
        $down = MigrationFixtures::links($this->harness->connection);
        self::assertSame(36, strlen((string) $down['runs'][11]));
        self::assertSame($down['runs'][11], $down['control']);
        self::assertSame($down['runs'][22], $down['proposals'][201]['run']);
        self::assertNull($down['proposals'][202]['run']);
        self::assertCount(2, array_unique(array_column($down['proposals'], 'id')));
        self::assertSame(36, strlen((string) $down['proposals'][201]['id']));
        self::assertSame(1, $this->storedCount('watering_proposal_state'));
        $this->idStep(true);
        $this->assertIntegerLinks();
        $this->assertWateringSchema();
        $this->assertProposalsDiscarded();
    }

    public function testWateringIdMigrationHandlesEmptyUpDownUp(): void
    {
        $this->prepareWatering(false);
        $this->idStep(true);
        $this->assertWateringSchema();
        $this->idStep(false);
        $this->idStep(true);
    }

    public function testWateringGuardsRejectOrphansAndInterruptedColumns(): void
    {
        $this->prepareWatering(true);
        $connection = $this->harness->connection;
        $connection->executeStatement("UPDATE watering_control SET active_run_id = 'missing' WHERE id = 1");
        $this->abort(true, 'watering_control.active_run_id references a missing');
        $connection->executeStatement("UPDATE watering_control SET active_run_id = 'ffffffff-0000-4000-8000-00000000000a' WHERE id = 1");
        $connection->executeStatement('ALTER TABLE watering_run ADD new_id INT DEFAULT NULL');
        $this->abort(true, 'Temporary columns from an interrupted run');
        $connection->executeStatement('ALTER TABLE watering_run DROP COLUMN new_id');
        self::assertSame('char', $connection->fetchOne('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['worker_lifecycle', 'watering_run', 'id']));
        $this->harness->step(self::IDS);
        $connection->close();
        MigrationFixtures::integerProposals($connection);
        $this->orphan('watering_proposal', 'run_id', 'duration_seconds = 201', 999);
        $this->abort(false, 'watering_proposal.run_id references a missing');
        $connection->executeStatement('UPDATE watering_proposal SET run_id = 2 WHERE duration_seconds = 201');
        $this->orphan('watering_control', 'active_run_id', 'id = 1', 999);
        $this->abort(false, 'watering_control.active_run_id references a missing');
        $connection->executeStatement('UPDATE watering_control SET active_run_id = 1 WHERE id = 1');
        $connection->executeStatement('ALTER TABLE watering_run ADD id_uuid CHAR(36) DEFAULT NULL');
        $this->abort(false, 'Temporary columns from an interrupted rollback');
        $connection->executeStatement('ALTER TABLE watering_run DROP COLUMN id_uuid');
        $connection->executeStatement('DELETE FROM watering_proposal');
        $this->assertIntegerLinks();
    }

    private function prepareWatering(bool $seed): void
    {
        $this->harness->migrate(self::PRE_IDS);
        $this->harness->connection->close();
        if ($seed) {
            MigrationFixtures::uuidWatering($this->harness->connection);
        }
    }

    private function idStep(bool $up): void
    {
        $this->harness->step(self::IDS, $up);
        $this->harness->reboot();
    }

    private function assertIntegerLinks(): void
    {
        $links = MigrationFixtures::links($this->harness->connection);
        $normalized = [
            'runs' => array_map('intval', $links['runs']),
            'proposals' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'run' => null === $row['run'] ? null : (int) $row['run']], $links['proposals']),
            'control' => null === $links['control'] ? null : (int) $links['control'],
        ];
        self::assertSame(['runs' => [11 => 1, 22 => 2, 33 => 3],
            'proposals' => [], 'control' => 1], $normalized);
    }

    private function assertWateringSchema(): void
    {
        $connection = $this->harness->connection;
        foreach ([['watering_run', 'id'], ['watering_proposal', 'id'],
            ['watering_proposal', 'run_id'], ['watering_control', 'active_run_id']] as [$table, $column]) {
            self::assertSame('int', $connection->fetchOne('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['worker_lifecycle', $table, $column]));
        }
        foreach (['watering_run', 'watering_proposal'] as $table) {
            self::assertStringContainsString('auto_increment', $connection->fetchOne('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['worker_lifecycle', $table, 'id']));
        }
        $this->assertIndex('watering_run', 'idx_watering_requested', ['requested_at'], 1);
        $this->assertIndex('watering_proposal', 'idx_proposal_device_created', ['device_id', 'created_at'], 1);
        $this->assertIndex('watering_control', 'IDX_3D8BB6061BDA27D3', ['active_run_id'], 1);
        $this->assertIndex('watering_proposal', 'IDX_B758F9C584E3FEC4', ['run_id'], 1);
        self::assertSame(['FK_3D8BB6061BDA27D3', 'FK_B758F9C584E3FEC4'], $connection->fetchFirstColumn('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? ORDER BY CONSTRAINT_NAME', ['worker_lifecycle', 'watering_run']));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN ('new_id', 'new_run_id', 'new_active_run_id', 'id_uuid', 'run_id_uuid', 'active_run_id_uuid')", ['worker_lifecycle']));
    }

    private function assertProposalsDiscarded(): void
    {
        self::assertSame(0, $this->storedCount('watering_proposal'));
        self::assertSame(0, $this->storedCount('watering_proposal_state'));
    }

    private function storedCount(string $table): int
    {
        return (int) $this->harness->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function abort(bool $up, string $fragment): void
    {
        [$status, $output] = $this->harness->step(self::IDS, $up, false);
        self::assertNotSame(0, $status);
        self::assertStringContainsString($fragment, preg_replace('/\s+/', ' ', $output));
    }

    private function orphan(string $table, string $column, string $where, int $value): void
    {
        $connection = $this->harness->connection;
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $connection->executeStatement(sprintf('UPDATE %s SET %s = ? WHERE %s', $table, $column, $where), [$value]);
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function upgradeLegacy(): void
    {
        foreach (['Version20260824163627', 'Version20260824192259', 'Version20260908195213'] as $version) {
            $this->harness->step($version);
        }
        $connection = $this->harness->connection;
        MigrationFixtures::legacy($connection);
        $before = MigrationFixtures::snapshot($connection);
        $this->harness->step('Version20260918120000');
        $this->harness->step('Version20260919151830');
        $connection->close();
        self::assertSame($before, MigrationFixtures::snapshot($connection));
        $rows = $connection->fetchAllAssociative('SELECT m.id, m.deduplication_id, m.type AS measurement_type, s.type AS sensor_type FROM measurement m JOIN sensor s ON s.id = m.sensor_id ORDER BY m.id');
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertNotNull($row['deduplication_id']);
            self::assertTrue(Uuid::isValid($row['deduplication_id']));
            self::assertSame($row['sensor_type'], $row['measurement_type']);
        }
        self::assertNotSame($rows[0]['deduplication_id'], $rows[1]['deduplication_id']);
        self::assertSame((int) $connection->fetchOne('SELECT COUNT(*) FROM measurement'),
            (int) $connection->fetchOne('SELECT COUNT(DISTINCT deduplication_id) FROM measurement'));
    }

    private function unique(\Closure $insert): void
    {
        try {
            $insert();
            self::fail('Expected the precise Doctrine unique constraint violation.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception);
        }
    }

    private function assertIndex(string $table, string $index, array $columns, int $nonUnique): void
    {
        $expected = [];
        foreach ($columns as $position => $column) {
            $expected[] = ['COLUMN_NAME' => $column, 'SEQ_IN_INDEX' => $position + 1, 'NON_UNIQUE' => $nonUnique];
        }
        self::assertSame($expected, MigrationFixtures::indexes($this->harness->connection, $table, $index));
    }
}