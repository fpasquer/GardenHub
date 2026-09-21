<?php

declare(strict_types=1);

namespace Tests\TelegramAlerts;

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
 * Test-only DBAL driver middleware simulating a failure between the
 * alert_processed_uplink marker's flush and the enclosing transaction's
 * commit: it lets the marker's own INSERT execute normally (so the row
 * physically exists, uncommitted) and then throws immediately after,
 * forcing ChirpStackUplinkHandler's wrapInTransaction() to close the
 * EntityManager and roll back everything (measurements, marker, alert
 * signal side effects) as one unit.
 *
 * Registered as a doctrine.middleware-tagged service only by the
 * marker-rollback test kernel; production never registers it.
 */
final class MarkerFlushFailureMiddleware implements Middleware
{
    private static ?MarkerFlushFailureConfig $config = null;

    public static function arm(MarkerFlushFailureConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public static function config(): ?MarkerFlushFailureConfig
    {
        return self::$config;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new MarkerFlushFailureDriver($driver);
    }
}

final class MarkerFlushFailureDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new MarkerFlushFailureConnection(parent::connect($params));
    }
}

final class MarkerFlushFailureConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): DriverStatement
    {
        $statement = parent::prepare($sql);

        if (!preg_match('/INSERT INTO alert_processed_uplink\s*\(([^)]+)\)/i', $sql, $matches)) {
            return $statement;
        }

        $columns = array_map('trim', explode(',', $matches[1]));
        $deduplicationIdParam = array_search('deduplication_id', $columns, true);
        if (false === $deduplicationIdParam) {
            return $statement;
        }

        return new MarkerFlushFailureStatement($statement, $deduplicationIdParam + 1);
    }
}

final class MarkerFlushFailureStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $boundValues = [];

    public function __construct(
        DriverStatement $statement,
        private readonly int $deduplicationIdParam,
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
        $config = MarkerFlushFailureMiddleware::config();
        $matchesTarget = null !== $config
            && $config->armed
            && ($this->boundValues[$this->deduplicationIdParam] ?? null) === $config->deduplicationId;

        // The real INSERT always runs first: the row must physically exist
        // (uncommitted) before the injected failure, exactly like a genuine
        // post-flush, pre-commit failure would leave it.
        $result = parent::execute();

        if ($matchesTarget) {
            $config->armed = false;
            throw new MarkerFlushFailureInjected($config->deduplicationId);
        }

        return $result;
    }
}

/**
 * Configuration for one deterministic marker-flush-failure injection.
 */
final class MarkerFlushFailureConfig
{
    public bool $armed = true;

    public function __construct(public readonly string $deduplicationId)
    {
    }
}

/**
 * Distinctive exception proving the observed handler failure was caused by
 * this deliberate injection rather than an unrelated error.
 */
final class MarkerFlushFailureInjected extends \RuntimeException
{
    public function __construct(public readonly string $deduplicationId)
    {
        parent::__construct(sprintf(
            'Injected test-only failure right after the alert_processed_uplink marker flush for deduplicationId "%s".',
            $deduplicationId
        ));
    }
}
