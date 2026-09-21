<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durable alert evaluation progress + processed-uplink markers (PR #10 review).
 *
 * alert_evaluation_progress keeps per-(alert_type, subject_key) replay and
 * ordering state independently of any alert_incident row, so protection
 * survives incident resolution. Backfill uses only the incident row holding
 * each key's max last_considered_measurement_id: that row's last_measured_at
 * is the same applied signal's timestamp, so the (id, measured_at) pair is
 * coherent and never mixes independent maxima from different rows.
 *
 * alert_processed_uplink records every uplink whose device-level alert
 * evaluation has run, keyed by its ChirpStack deduplicationId. Backfilled
 * from the measurement table's distinct deduplication ids.
 *
 * Known backfill limitations: uplinks that were entirely invalid or empty
 * before this migration left no rows anywhere, so one duplicate
 * invalid_reading signal per such replayed uplink is possible once after
 * deploy; subjects with only healthy pre-deploy readings start with a NULL
 * measured_at watermark.
 */
final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alert_evaluation_progress and alert_processed_uplink tables for durable replay protection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE alert_evaluation_progress (
                id INT AUTO_INCREMENT NOT NULL,
                alert_type VARCHAR(30) NOT NULL,
                subject_key VARCHAR(150) NOT NULL,
                last_considered_measurement_id INT DEFAULT NULL,
                last_considered_measured_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_evaluation_progress_subject ON alert_evaluation_progress (alert_type, subject_key)');

        $this->addSql(<<<'SQL'
            CREATE TABLE alert_processed_uplink (
                id INT AUTO_INCREMENT NOT NULL,
                deduplication_id VARCHAR(36) NOT NULL,
                first_processed_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_processed_uplink_dedup ON alert_processed_uplink (deduplication_id)');

        // Backfill progress from the incident row holding each key's max id;
        // that row's last_measured_at is the same signal's timestamp.
        $this->addSql(<<<'SQL'
            INSERT INTO alert_evaluation_progress
                (alert_type, subject_key, last_considered_measurement_id, last_considered_measured_at, created_at, updated_at)
            SELECT i.alert_type, i.subject_key, i.last_considered_measurement_id, i.last_measured_at, NOW(), NOW()
            FROM alert_incident i
            INNER JOIN (
                SELECT alert_type, subject_key, MAX(last_considered_measurement_id) AS max_id
                FROM alert_incident
                WHERE last_considered_measurement_id IS NOT NULL
                GROUP BY alert_type, subject_key
            ) m ON m.alert_type = i.alert_type AND m.subject_key = i.subject_key AND m.max_id = i.last_considered_measurement_id
            SQL);

        // Backfill processed uplinks from measurements' distinct dedup ids.
        $this->addSql(<<<'SQL'
            INSERT INTO alert_processed_uplink (deduplication_id, first_processed_at)
            SELECT deduplication_id, MIN(created_at)
            FROM measurement
            GROUP BY deduplication_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert_evaluation_progress');
        $this->addSql('DROP TABLE alert_processed_uplink');
    }
}
