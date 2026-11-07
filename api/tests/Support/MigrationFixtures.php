<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

final class MigrationFixtures
{
    public static function legacy(Connection $connection): void
    {
        $connection->executeStatement("INSERT INTO device (id, name, dev_eui, created_at) VALUES (1, 'Upgrade device A', 'upgrade-eui-aaa', NOW()), (2, 'Upgrade device B', 'upgrade-eui-bbb', NOW())");
        $sql = 'INSERT INTO sensor (id, device_id, type, unit, label, created_at) VALUES (?, ?, ?, ?, ?, NOW())';
        foreach ([[1, 1, 'soil_temperature', "\u{00B0}C", 'temp_SOIL'],
            [2, 1, 'battery', 'V', 'BatV'], [3, 2, 'soil_temperature', "\u{00B0}C", 'temp_SOIL']] as $row) {
            $connection->executeStatement($sql, $row);
        }
        $sql = 'INSERT INTO measurement (id, sensor_id, value, measured_at, created_at) VALUES (?, ?, ?, ?, NOW())';
        foreach ([[1, 1, 18.5, '2020-01-01 12:00:00'], [2, 1, 18.7, '2020-01-01 12:00:00'],
            [3, 1, 19.0, '2020-01-01 13:00:00'], [4, 2, 3.3, '2020-01-01 12:00:00'],
            [5, 3, 17.0, '2020-01-02 08:00:00']] as $row) {
            $connection->executeStatement($sql, $row);
        }
    }

    public static function snapshot(Connection $connection): array
    {
        return ['measurements' => $connection->fetchAllAssociative('SELECT id, sensor_id, value, measured_at, created_at FROM measurement ORDER BY id'),
            'sensors' => $connection->fetchAllAssociative('SELECT * FROM sensor ORDER BY id'),
            'devices' => $connection->fetchAllAssociative('SELECT * FROM device ORDER BY id')];
    }

    public static function indexes(Connection $connection, string $table, string $index): array
    {
        $rows = $connection->fetchAllAssociative('SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            ['worker_lifecycle', $table, $index]);

        return array_map(static fn (array $row): array => [
            'COLUMN_NAME' => (string) $row['COLUMN_NAME'],
            'SEQ_IN_INDEX' => (int) $row['SEQ_IN_INDEX'],
            'NON_UNIQUE' => (int) $row['NON_UNIQUE']], $rows);
    }

    public static function uuidWatering(Connection $connection): void
    {
        $connection->executeStatement("INSERT INTO device (id, name, created_at) VALUES (1, 'Watering ids', NOW())");
        $sql = "INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (?, ?, 'finished', ?, NOW())";
        foreach ([['ffffffff-0000-4000-8000-00000000000a', 11, '2026-01-01 09:00:00'],
            ['00000000-0000-4000-8000-00000000000b', 22, '2026-01-01 10:00:00'],
            ['77777777-0000-4000-8000-00000000000c', 33, '2026-01-01 11:00:00']] as $row) {
            $connection->executeStatement($sql, $row);
        }
        $sql = "INSERT INTO watering_proposal (id, device_id, status, created_at, expires_at, duration_seconds, readings_json, notification_status, run_id) VALUES (?, 1, 'approved', ?, NOW(), ?, '[]', 'final', ?)";
        foreach ([['bbbbbbbb-0000-4000-8000-000000000001', '2026-01-01 08:00:00', 101, '00000000-0000-4000-8000-00000000000b'],
            ['aaaaaaaa-0000-4000-8000-000000000002', '2026-01-01 07:00:00', 102, null],
            ['cccccccc-0000-4000-8000-000000000003', '2026-01-01 09:00:00', 103, '77777777-0000-4000-8000-00000000000c']] as $row) {
            $connection->executeStatement($sql, $row);
        }
        $connection->executeStatement("INSERT INTO watering_proposal (id, device_id, status, created_at, expires_at, duration_seconds, readings_json, notification_status, message_id) VALUES ('dddddddd-0000-4000-8000-000000000004', 1, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), 105, '[]', 'sent', 555)");
        $connection->executeStatement("INSERT INTO watering_proposal_state (device_id, actuator_topic, prompt_count, last_prompt_at) VALUES (1, 'test/topic', 2, NOW())");
        $connection->executeStatement("UPDATE watering_control SET active_run_id = 'ffffffff-0000-4000-8000-00000000000a' WHERE id = 1");
    }

    public static function integerProposals(Connection $connection): void
    {
        $sql = "INSERT INTO watering_proposal (device_id, status, created_at, expires_at, duration_seconds, readings_json, notification_status, run_id) VALUES (1, 'approved', NOW(), NOW(), ?, '[]', 'final', ?)";
        $connection->executeStatement($sql, [201, 2]);
        $connection->executeStatement($sql, [202, null]);
        $connection->executeStatement("INSERT INTO watering_proposal_state (device_id, actuator_topic, prompt_count, last_prompt_at) VALUES (1, 'test/topic', 1, NOW())");
    }

    public static function links(Connection $connection): array
    {
        $runs = [];
        foreach ($connection->fetchAllAssociative('SELECT id, requested_seconds FROM watering_run') as $row) {
            $runs[(int) $row['requested_seconds']] = $row['id'];
        }
        $proposals = [];
        foreach ($connection->fetchAllAssociative('SELECT id, duration_seconds, run_id FROM watering_proposal') as $row) {
            $proposals[(int) $row['duration_seconds']] = ['id' => $row['id'], 'run' => $row['run_id']];
        }
        $control = $connection->fetchOne('SELECT active_run_id FROM watering_control WHERE id = 1');
        ksort($runs);
        ksort($proposals);

        return ['runs' => $runs, 'proposals' => $proposals, 'control' => false === $control ? null : $control];
    }
}