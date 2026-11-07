<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102180000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Persist two-stage watering cycles and invalidate old approval terms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watering_run ADD actuator_topic VARCHAR(255) DEFAULT NULL, ADD second_stage_due_at DATETIME DEFAULT NULL, ADD safety_probe_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_control ADD actuator_seen_at DATETIME DEFAULT NULL, ADD actuator_safe TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_proposal ADD stage_count INT DEFAULT 1 NOT NULL, ADD break_seconds INT DEFAULT 0 NOT NULL, ADD rendered_text LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE TABLE watering_stage (id INT AUTO_INCREMENT NOT NULL, run_id INT NOT NULL, position INT NOT NULL, requested_seconds INT NOT NULL, status VARCHAR(20) NOT NULL, requested_at DATETIME DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, INDEX IDX_FB399F8684E3FEC4 (run_id), UNIQUE INDEX uniq_watering_stage (run_id, position), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE watering_stage ADD CONSTRAINT FK_FB399F8684E3FEC4 FOREIGN KEY (run_id) REFERENCES watering_run (id) ON DELETE CASCADE');
        $this->addSql("UPDATE watering_proposal SET status = 'invalidated', failure = 'Watering cycle terms changed; a new approval is required' WHERE status = 'pending'");
        $this->addSql(<<<'SQL'
            DELETE s FROM watering_proposal_state s
            WHERE EXISTS (
                SELECT 1 FROM watering_proposal p
                WHERE p.device_id = s.device_id
                    AND COALESCE(p.actuator_topic, '') = s.actuator_topic
                    AND p.status = 'invalidated'
                    AND p.failure = 'Watering cycle terms changed; a new approval is required'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            0 !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM watering_stage'),
            'Cannot discard recorded two-stage watering history.',
        );
        $this->addSql('DROP TABLE watering_stage');
        $this->addSql('ALTER TABLE watering_proposal DROP stage_count, DROP break_seconds, DROP rendered_text');
        $this->addSql('ALTER TABLE watering_control DROP actuator_seen_at, DROP actuator_safe');
        $this->addSql('ALTER TABLE watering_run DROP actuator_topic, DROP second_stage_due_at, DROP safety_probe_at');
    }
}
