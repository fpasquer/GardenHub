<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind watering proposals and prompt counters to their actuator topic';
    }

    public function up(Schema $schema): void
    {
        // NULL marks a proposal created before the topic was recorded; it can never be approved.
        $this->addSql('ALTER TABLE watering_proposal ADD actuator_topic VARCHAR(255) DEFAULT NULL');
        // '' marks a legacy counter; no valid configured topic is blank.
        $this->addSql("ALTER TABLE watering_proposal_state ADD actuator_topic VARCHAR(255) NOT NULL DEFAULT '', DROP PRIMARY KEY, ADD PRIMARY KEY (device_id, actuator_topic)");
    }

    public function down(Schema $schema): void
    {
        // Keep the highest count and latest prompt so a rollback can only delay a reminder.
        $this->addSql('UPDATE watering_proposal_state s JOIN (SELECT device_id, MIN(actuator_topic) AS kept, MAX(prompt_count) AS prompt_count, MAX(last_prompt_at) AS last_prompt_at FROM watering_proposal_state GROUP BY device_id) a ON a.device_id = s.device_id AND a.kept = s.actuator_topic SET s.prompt_count = a.prompt_count, s.last_prompt_at = a.last_prompt_at');
        $this->addSql('DELETE s FROM watering_proposal_state s JOIN (SELECT device_id, MIN(actuator_topic) AS kept FROM watering_proposal_state GROUP BY device_id) a ON a.device_id = s.device_id WHERE s.actuator_topic <> a.kept');
        $this->addSql('ALTER TABLE watering_proposal_state DROP PRIMARY KEY, DROP COLUMN actuator_topic, ADD PRIMARY KEY (device_id)');
        $this->addSql('ALTER TABLE watering_proposal DROP COLUMN actuator_topic');
    }
}
