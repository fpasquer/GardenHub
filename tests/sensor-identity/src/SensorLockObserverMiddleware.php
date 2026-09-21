<?php

declare(strict_types=1);

namespace Tests\SensorIdentity;

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
 * Test-only DBAL driver middleware that runs an arbitrary callback exactly
 * once, synchronously, right after SensorRepository::lockAndFetchIdentity()'s
 * `FOR UPDATE` statement executes for one armed sensor id — i.e. after the
 * main connection's own row lock is genuinely held. Used to prove, via a
 * second real connection with a bounded lock-wait timeout, that a concurrent
 * lock attempt on the same row actually blocks instead of proceeding.
 *
 * DoctrineBundle wires any service tagged `doctrine.middleware` into the
 * shared default connection; only a test kernel registers this (see
 * concurrency.php). Production never does.
 */
final class SensorLockObserverMiddleware implements Middleware
{
    private static ?SensorLockObserverConfig $config = null;

    public static function arm(SensorLockObserverConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public static function config(): ?SensorLockObserverConfig
    {
        return self::$config;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new SensorLockObserverDriver($driver);
    }
}

final class SensorLockObserverDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new SensorLockObserverConnection(parent::connect($params));
    }
}

final class SensorLockObserverConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): DriverStatement
    {
        $statement = parent::prepare($sql);

        if (!preg_match('/SELECT device_id, type, unit FROM sensor WHERE id = \?\s*FOR UPDATE/i', $sql)) {
            return $statement;
        }

        return new SensorLockObserverStatement($statement);
    }
}

final class SensorLockObserverStatement extends AbstractStatementMiddleware
{
    private mixed $boundSensorId = null;

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->boundSensorId = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): DriverResult
    {
        // The wrapped statement runs first: by the time our callback fires,
        // this connection's own row lock (if the row exists) is already held.
        $result = parent::execute();

        $config = SensorLockObserverMiddleware::config();
        if (null !== $config && $config->armed && null !== $this->boundSensorId && (int) $this->boundSensorId === $config->sensorId) {
            $config->armed = false;
            ($config->onLocked)();
        }

        return $result;
    }
}

final class SensorLockObserverConfig
{
    public bool $armed = true;

    public function __construct(
        public readonly int $sensorId,
        public readonly \Closure $onLocked,
    ) {
    }
}
