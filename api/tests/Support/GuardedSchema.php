<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

final class GuardedSchema
{
    public static function create(EntityManagerInterface $manager, string $database): void
    {
        self::verify($manager, $database);
        (new SchemaTool($manager))->updateSchema(
            $manager->getMetadataFactory()->getAllMetadata(),
        );
    }

    public static function resetMeasurements(EntityManagerInterface $manager, string $database): void
    {
        self::verify($manager, $database);
        $connection = $manager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (['measurement', 'sensor', 'device'] as $table) {
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
        $manager->clear();
    }

    private static function verify(EntityManagerInterface $manager, string $database): void
    {
        if ('1' !== getenv('GARDENHUB_LIFECYCLE_TESTS')
            || $database !== $manager->getConnection()->getDatabase()) {
            throw new \RuntimeException('Refusing to modify a non-test database.');
        }
    }
}