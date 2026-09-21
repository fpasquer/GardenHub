<?php

declare(strict_types=1);

namespace Tests\SensorIdentity;

use App\Entity\Sensor;
use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Test-only Doctrine listener that, exactly once, performs a real concurrent
 * identity change (a second, independent PDO connection, committed
 * immediately) the moment a specific Sensor is hydrated — i.e. right after a
 * request's own deserialization resolved it (Doctrine populates an entity's
 * fields from the query result before firing postLoad, so this cannot affect
 * what that request already captured), but before that same request's
 * Processor re-locks and re-reads it. Deterministically proves the
 * Measurement-create guard rejects a sensor whose identity changed after the
 * request captured it, without relying on timing or sleeps.
 */
final class SensorPostLoadRelabeler
{
    private static ?SensorPostLoadRelabelConfig $config = null;

    public static function arm(SensorPostLoadRelabelConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        $config = self::$config;
        if (!$entity instanceof Sensor || null === $config || !$config->armed || $entity->getId() !== $config->sensorId) {
            return;
        }
        $config->armed = false;

        $pdo = new \PDO($config->pdoDsn, $config->pdoUser, $config->pdoPassword);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(sprintf("UPDATE sensor SET type = '%s' WHERE id = %d", $config->newType, $config->sensorId));
    }
}

final class SensorPostLoadRelabelConfig
{
    public bool $armed = true;

    public function __construct(
        public readonly int $sensorId,
        public readonly string $newType,
        public readonly string $pdoDsn,
        public readonly string $pdoUser,
        public readonly string $pdoPassword,
    ) {
    }
}
