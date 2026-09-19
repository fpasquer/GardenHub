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

        if (!preg_match('/INSERT INTO measurement\s*\(([^)]+)\)/i', $sql, $matches)) {
            return $statement;
        }

        // Column order is parsed from the SQL text itself so this stays correct
        // if the entity mapping ever reorders the INSERT's column list.
        $columns = array_map('trim', explode(',', $matches[1]));
        $deduplicationIdParam = array_search('deduplication_id', $columns, true);
        $typeParam = array_search('type', $columns, true);

        if (false === $deduplicationIdParam || false === $typeParam) {
            return $statement;
        }

        return new CollisionStatement($statement, $deduplicationIdParam + 1, $typeParam + 1);
    }
}

final class CollisionStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $boundValues = [];

    public function __construct(
        DriverStatement $statement,
        private readonly int $deduplicationIdParam,
        private readonly int $typeParam,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->boundValues[$param] = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): DriverResult
    {
        $config = CollisionMiddleware::config();
        // Scoped to event A's own battery insert so a differently-ordered flush
        // (e.g. soil_temperature persisted first) can never mis-trigger this.
        $matchesEventA = null !== $config
            && $config->armed
            && ($this->boundValues[$this->deduplicationIdParam] ?? null) === $config->deduplicationId
            && ($this->boundValues[$this->typeParam] ?? null) === 'battery';

        if ($matchesEventA) {
            // Reaching this statement proves the handler's pre-check already ran
            // (it queries before building/persisting entities) and is about to flush.
            ScenarioRecorder::record('a_reached_flush_after_pre_check', ['deduplicationId' => $config->deduplicationId]);

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
            ScenarioRecorder::record('collision_row_committed', ['deduplicationId' => $config->deduplicationId, 'value' => 9.9]);
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
