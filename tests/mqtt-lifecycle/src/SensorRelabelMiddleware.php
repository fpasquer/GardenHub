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
use Doctrine\DBAL\ParameterType;

/**
 * Test-only DBAL driver middleware that deterministically relabels a sensor
 * via a second, independent connection right before the handler's own
 * SensorRepository::lockAndFetchIdentity() FOR UPDATE statement executes for
 * that sensor id — i.e. after the handler already resolved/captured the
 * sensor's expected identity, but before its own lock observes the current
 * row. The second connection's plain UPDATE runs uncontended here (the main
 * connection has not yet taken the row lock) and commits immediately, so the
 * handler's own locked read deterministically observes the new value. No
 * sleeps, no lock contention.
 *
 * DoctrineBundle wires any service tagged `doctrine.middleware` into the
 * shared default connection. Only the identity-race test kernel registers
 * this; production never does.
 */
final class SensorRelabelMiddleware implements Middleware
{
    private static ?SensorRelabelConfig $config = null;

    public static function arm(SensorRelabelConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public static function config(): ?SensorRelabelConfig
    {
        return self::$config;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new SensorRelabelDriver($driver);
    }
}

final class SensorRelabelDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new SensorRelabelConnection(parent::connect($params));
    }
}

final class SensorRelabelConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): DriverStatement
    {
        $statement = parent::prepare($sql);

        if (!preg_match('/SELECT device_id, type, unit FROM sensor WHERE id = \?\s*FOR UPDATE/i', $sql)) {
            return $statement;
        }

        return new SensorRelabelStatement($statement);
    }
}

final class SensorRelabelStatement extends AbstractStatementMiddleware
{
    private mixed $boundSensorId = null;

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->boundSensorId = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): DriverResult
    {
        $config = SensorRelabelMiddleware::config();
        if (null !== $config && $config->armed && null !== $this->boundSensorId && (int) $this->boundSensorId === $config->sensorId) {
            $config->armed = false;

            $pdo = new \PDO($config->pdoDsn, $config->pdoUser, $config->pdoPassword);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec(sprintf("UPDATE sensor SET unit = '%s' WHERE id = %d", $config->newUnit, $config->sensorId));
        }

        return parent::execute();
    }
}

final class SensorRelabelConfig
{
    public bool $armed = true;

    public function __construct(
        public readonly int $sensorId,
        public readonly string $newUnit,
        public readonly string $pdoDsn,
        public readonly string $pdoUser,
        public readonly string $pdoPassword,
    ) {
    }
}
