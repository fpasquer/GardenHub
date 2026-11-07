<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Preserve watering proposal notification classification; legacy classification stays unknown';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watering_proposal ADD renewed TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watering_proposal DROP renewed');
    }
}