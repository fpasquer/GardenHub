<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial domain model: Device, Sensor and Measurement tables.
 */
final class Version20260824163627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial domain model: device, sensor and measurement tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, dev_eui VARCHAR(32) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_92FB68E5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE measurement (id INT AUTO_INCREMENT NOT NULL, value DOUBLE PRECISION NOT NULL, measured_at DATETIME NOT NULL, created_at DATETIME NOT NULL, sensor_id INT NOT NULL, INDEX IDX_2CE0D811A247991F (sensor_id), INDEX idx_measurement_sensor_measured_at (sensor_id, measured_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sensor (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, unit VARCHAR(20) NOT NULL, label VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, device_id INT NOT NULL, INDEX IDX_BC8617B094A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE measurement ADD CONSTRAINT FK_2CE0D811A247991F FOREIGN KEY (sensor_id) REFERENCES sensor (id)');
        $this->addSql('ALTER TABLE sensor ADD CONSTRAINT FK_BC8617B094A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE measurement DROP FOREIGN KEY FK_2CE0D811A247991F');
        $this->addSql('ALTER TABLE sensor DROP FOREIGN KEY FK_BC8617B094A4C7D4');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE measurement');
        $this->addSql('DROP TABLE sensor');
    }
}
