<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist dev watering cycles and serialize manual requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE watering_control (id INT NOT NULL, active_run_id CHAR(36) DEFAULT NULL, last_request_at DATETIME DEFAULT NULL, monitor_seen_at DATETIME DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO watering_control (id) VALUES (1)');
        $this->addSql('CREATE TABLE watering_run (id CHAR(36) NOT NULL, requested_seconds INT NOT NULL, status VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL, deadline_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, last_state_at DATETIME DEFAULT NULL, last_state VARCHAR(10) DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id), INDEX idx_watering_requested (requested_at))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE watering_run');
        $this->addSql('DROP TABLE watering_control');
    }
}
