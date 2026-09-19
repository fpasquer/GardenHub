<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * Test-only DBAL driver middleware that deterministically injects a competing
 * measurement row after the handler's pre-check but before the flush's INSERT.
 *
 * DoctrineBundle wires any service tagged `doctrine.middleware` into the shared
 * default connection — the same connection used by both the ORM entity manager
 * and the Messenger doctrine transport. Only the delivery test kernel registers
 * it (see delivery-guarantees.php); production never does.
 *
 * The tagged service is the Middleware factory: DoctrineBundle calls wrap() to
 * decorate the real driver with CollisionDriver below.
 */
final class CollisionMiddleware implements Middleware
{
    private static ?CollisionConfig $config = null;

    public static function arm(CollisionConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public static function config(): ?CollisionConfig
    {
        return self::$config;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new CollisionDriver($driver);
    }
}

final class CollisionDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new CollisionConnection(parent::connect($params));
    }
}

final class CollisionConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): DriverStatement
    {
        $statement = parent::prepare($sql);

        return str_contains($sql, 'INSERT INTO measurement')
            ? new CollisionStatement($statement)
            : $statement;
    }
}

final class CollisionStatement extends AbstractStatementMiddleware
{
    public function execute(): DriverResult
    {
        $config = CollisionMiddleware::config();
        if (null !== $config && $config->armed) {
            // Inject the conflicting (deduplicationId, battery) row via a separate
            // PDO connection, committed immediately, so the original INSERT then
            // collides with the unique (deduplication_id, type) index. The flush's
            // INSERT always runs after the handler's pre-check, so this is the
            // deterministic post-pre-check / pre-flush window. No sleeps.
            $pdo = new \PDO($config->pdoDsn, $config->pdoUser, $config->pdoPassword);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(sprintf(
                "INSERT INTO measurement (sensor_id, value, measured_at, created_at, deduplication_id, type) VALUES (%d, 9.9, '2020-01-01 12:00:00', NOW(), '%s', 'battery')",
                $config->sensorId,
                $config->deduplicationId
            ));
            $config->armed = false;
        }

        return parent::execute();
    }
}

/**
 * Configuration for one deterministic collision injection.
 */
final class CollisionConfig
{
    public bool $armed = true;

    public function __construct(
        public readonly int $sensorId,
        public readonly string $deduplicationId,
        public readonly string $pdoDsn,
        public readonly string $pdoUser,
        public readonly string $pdoPassword,
    ) {
    }
}
