<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alert incident lifecycle table for the Telegram alerting feature.
 *
 * is_open is a database-generated column (never written by application
 * code) deriving purely from status, so it cannot drift out of sync:
 * NULL once resolved (unlimited resolved rows accumulate freely), 1 while
 * pending/active. The unique index on it enforces at most one open
 * incident per (alert_type, subject_key) at the database level.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create alert_incident table for the Telegram alerting feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE alert_incident (
                id INT AUTO_INCREMENT NOT NULL,
                alert_type VARCHAR(30) NOT NULL,
                subject_key VARCHAR(150) NOT NULL,
                status VARCHAR(20) NOT NULL,
                is_open TINYINT GENERATED ALWAYS AS (CASE WHEN status IN ('pending', 'active') THEN 1 ELSE NULL END) VIRTUAL,
                confirmation_count INT NOT NULL,
                recovery_count INT NOT NULL,
                first_detected_at DATETIME NOT NULL,
                last_evaluated_at DATETIME NOT NULL,
                observed_value DOUBLE PRECISION DEFAULT NULL,
                last_value_unit VARCHAR(20) DEFAULT NULL,
                last_measured_at DATETIME DEFAULT NULL,
                last_considered_measurement_id INT DEFAULT NULL,
                opened_notified_at DATETIME DEFAULT NULL,
                last_reminder_at DATETIME DEFAULT NULL,
                next_reminder_at DATETIME DEFAULT NULL,
                recovery_notified_at DATETIME DEFAULT NULL,
                acknowledged_at DATETIME DEFAULT NULL,
                acknowledged_by VARCHAR(100) DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                resolved_by VARCHAR(100) DEFAULT NULL,
                resolution_reason VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_incident_open ON alert_incident (alert_type, subject_key, is_open)');
        $this->addSql('CREATE INDEX idx_alert_incident_subject ON alert_incident (alert_type, subject_key)');
        $this->addSql('CREATE INDEX idx_alert_incident_reminder ON alert_incident (status, next_reminder_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert_incident');
    }
}
