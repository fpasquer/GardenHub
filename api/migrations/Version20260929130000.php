<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Watering run and proposal ids become INT AUTO_INCREMENT (like every other
 * Doctrine id), and the two run references become real foreign keys.
 *
 * This migration is deliberately non-transactional: MySQL commits implicitly
 * around DDL, so a transaction would give no rollback. Run it with the
 * writers stopped and after a backup. preUp() aborts before any DDL when the
 * data cannot be converted, and when a previous partial run left temporary
 * columns behind.
 *
 * down() restores CHAR(36) columns but cannot restore the original UUID
 * values; every run and proposal receives a fresh UUID().
 */
final class Version20260929130000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Watering run and proposal ids become INT AUTO_INCREMENT; run references become foreign keys';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(
            $this->orphans('watering_control', 'active_run_id') > 0,
            'watering_control.active_run_id references a missing watering_run; fix it before migrating.',
        );
        $this->abortIf(
            $this->orphans('watering_proposal', 'run_id') > 0,
            'watering_proposal.run_id references a missing watering_run; fix it before migrating.',
        );
        $this->abortIf(
            $this->leftoverColumns(['new_id', 'new_active_run_id', 'new_run_id']) > 0,
            'Temporary columns from an interrupted run exist (new_id, new_active_run_id, new_run_id); drop them before retrying.',
        );
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watering_run ADD new_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_proposal ADD new_id INT DEFAULT NULL, ADD new_run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_control ADD new_active_run_id INT DEFAULT NULL');
        $this->addSql('UPDATE watering_run r JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY requested_at, id) AS n FROM watering_run) o ON o.id = r.id SET r.new_id = o.n');
        $this->addSql('UPDATE watering_proposal p JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at, id) AS n FROM watering_proposal) o ON o.id = p.id SET p.new_id = o.n');
        $this->addSql('UPDATE watering_control c JOIN watering_run r ON r.id = c.active_run_id SET c.new_active_run_id = r.new_id');
        $this->addSql('UPDATE watering_proposal p JOIN watering_run r ON r.id = p.run_id SET p.new_run_id = r.new_id');

        $this->addSql('ALTER TABLE watering_run DROP PRIMARY KEY, DROP COLUMN id');
        $this->addSql('ALTER TABLE watering_run CHANGE new_id id INT NOT NULL AUTO_INCREMENT PRIMARY KEY');
        $this->addSql('ALTER TABLE watering_proposal DROP PRIMARY KEY, DROP COLUMN id, DROP COLUMN run_id');
        $this->addSql('ALTER TABLE watering_proposal CHANGE new_id id INT NOT NULL AUTO_INCREMENT PRIMARY KEY');
        $this->addSql('ALTER TABLE watering_proposal CHANGE new_run_id run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_control DROP COLUMN active_run_id');
        $this->addSql('ALTER TABLE watering_control CHANGE new_active_run_id active_run_id INT DEFAULT NULL');

        // Indexes and foreign keys last, with the names Doctrine generates for the mapping.
        $this->addSql('CREATE INDEX IDX_3D8BB6061BDA27D3 ON watering_control (active_run_id)');
        $this->addSql('ALTER TABLE watering_control ADD CONSTRAINT FK_3D8BB6061BDA27D3 FOREIGN KEY (active_run_id) REFERENCES watering_run (id)');
        $this->addSql('CREATE INDEX IDX_B758F9C584E3FEC4 ON watering_proposal (run_id)');
        $this->addSql('ALTER TABLE watering_proposal ADD CONSTRAINT FK_B758F9C584E3FEC4 FOREIGN KEY (run_id) REFERENCES watering_run (id)');
    }

    public function preDown(Schema $schema): void
    {
        $this->abortIf(
            $this->orphans('watering_control', 'active_run_id') > 0,
            'watering_control.active_run_id references a missing watering_run; fix it before rolling back.',
        );
        $this->abortIf(
            $this->orphans('watering_proposal', 'run_id') > 0,
            'watering_proposal.run_id references a missing watering_run; fix it before rolling back.',
        );
        $this->abortIf(
            $this->leftoverColumns(['id_uuid', 'active_run_id_uuid', 'run_id_uuid']) > 0,
            'Temporary columns from an interrupted rollback exist (id_uuid, active_run_id_uuid, run_id_uuid); drop them before retrying.',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watering_control DROP FOREIGN KEY FK_3D8BB6061BDA27D3');
        $this->addSql('DROP INDEX IDX_3D8BB6061BDA27D3 ON watering_control');
        $this->addSql('ALTER TABLE watering_proposal DROP FOREIGN KEY FK_B758F9C584E3FEC4');
        $this->addSql('DROP INDEX IDX_B758F9C584E3FEC4 ON watering_proposal');

        $this->addSql('ALTER TABLE watering_run ADD id_uuid CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_proposal ADD id_uuid CHAR(36) DEFAULT NULL, ADD run_id_uuid CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_control ADD active_run_id_uuid CHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE watering_run SET id_uuid = UUID()');
        $this->addSql('UPDATE watering_proposal SET id_uuid = UUID()');
        $this->addSql('UPDATE watering_control c JOIN watering_run r ON r.id = c.active_run_id SET c.active_run_id_uuid = r.id_uuid');
        $this->addSql('UPDATE watering_proposal p JOIN watering_run r ON r.id = p.run_id SET p.run_id_uuid = r.id_uuid');

        $this->addSql('ALTER TABLE watering_run MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE watering_run DROP PRIMARY KEY, DROP COLUMN id');
        $this->addSql('ALTER TABLE watering_run CHANGE id_uuid id CHAR(36) NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE watering_proposal MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE watering_proposal DROP PRIMARY KEY, DROP COLUMN id, DROP COLUMN run_id');
        $this->addSql('ALTER TABLE watering_proposal CHANGE id_uuid id CHAR(36) NOT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE watering_proposal CHANGE run_id_uuid run_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE watering_control DROP COLUMN active_run_id');
        $this->addSql('ALTER TABLE watering_control CHANGE active_run_id_uuid active_run_id CHAR(36) DEFAULT NULL');
    }

    /** Rows whose reference points at no watering_run row. */
    private function orphans(string $table, string $column): int
    {
        return (int) $this->connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %1$s t LEFT JOIN watering_run r ON r.id = t.%2$s WHERE t.%2$s IS NOT NULL AND r.id IS NULL',
            $table,
            $column,
        ));
    }

    /** @param list<string> $columns */
    private function leftoverColumns(array $columns): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?) AND column_name IN (?, ?, ?)',
            ['watering_run', 'watering_proposal', 'watering_control', ...$columns],
        );
    }
}
