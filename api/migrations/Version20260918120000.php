<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds idempotent uplink storage: measurement.deduplication_id + measurement.type
 * with a unique (deduplication_id, type) index.
 *
 * Deploy order: stop all measurement writers (worker, consumer, API), drain the
 * async and failed queues with the OLD consumer, confirm both queues empty, then
 * run this migration and start the updated services.
 */
final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Measurement idempotency: deduplication_id + type columns and a unique (deduplication_id, type) index';
    }

    public function up(Schema $schema): void
    {
        // 1. Add columns nullable so the backfill can populate existing rows.
        $this->addSql('ALTER TABLE measurement ADD deduplication_id VARCHAR(36) DEFAULT NULL, ADD type VARCHAR(50) DEFAULT NULL');

        // 2. Backfill: type from the owning sensor, a unique UUID per row so the
        //    new unique index cannot collide on legacy data. Historical duplicates
        //    are intentionally left as-is.
        $this->addSql('UPDATE measurement m JOIN sensor s ON s.id = m.sensor_id SET m.type = s.type, m.deduplication_id = UUID()');

        // 3. Enforce NOT NULL now that every row is populated.
        $this->addSql('ALTER TABLE measurement MODIFY deduplication_id VARCHAR(36) NOT NULL, MODIFY type VARCHAR(50) NOT NULL');

        // 4. Idempotency guard.
        $this->addSql('CREATE UNIQUE INDEX uniq_measurement_dedup_type ON measurement (deduplication_id, type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_measurement_dedup_type ON measurement');
        $this->addSql('ALTER TABLE measurement DROP deduplication_id, DROP type');
    }
}
