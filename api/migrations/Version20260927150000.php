<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Durable dev watering proposals, dry episodes and Telegram polling offset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE watering_proposal_state (device_id INT NOT NULL, prompt_count INT NOT NULL DEFAULT 0, last_prompt_at DATETIME DEFAULT NULL, PRIMARY KEY(device_id), CONSTRAINT FK_WPS_DEVICE FOREIGN KEY (device_id) REFERENCES device (id))');
        $this->addSql('CREATE TABLE watering_proposal (id CHAR(36) NOT NULL, device_id INT NOT NULL, status VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, duration_seconds INT NOT NULL, readings_json JSON NOT NULL, last_attempt_at DATETIME DEFAULT NULL, message_id BIGINT DEFAULT NULL, notification_status VARCHAR(24) NOT NULL, decided_at DATETIME DEFAULT NULL, run_id CHAR(36) DEFAULT NULL, failure VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id), INDEX idx_proposal_device_created (device_id, created_at), CONSTRAINT FK_WP_DEVICE FOREIGN KEY (device_id) REFERENCES device (id))');
        $this->addSql('CREATE TABLE watering_telegram_progress (id INT NOT NULL, next_update_id BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO watering_telegram_progress (id, next_update_id) VALUES (1, 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE watering_telegram_progress');
        $this->addSql('DROP TABLE watering_proposal');
        $this->addSql('DROP TABLE watering_proposal_state');
    }
}
