<?php

declare(strict_types=1);

namespace Tests\TelegramAlerts;

use App\Entity\AlertIncident;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * Test-only DBAL driver middleware that resolves an incident right after
 * EvaluateAlertsHandler's own findDueForReminder() SELECT returns it as due,
 * but before sendReminder()'s locking refresh() re-checks it - the exact
 * window the production recheck exists to guard.
 *
 * Registered as a doctrine.middleware-tagged service only by the concurrency
 * test kernel; production never registers it.
 */
final class ReminderCollisionMiddleware implements Middleware
{
    private static ?ReminderCollisionConfig $config = null;

    public static function arm(ReminderCollisionConfig $config): void
    {
        self::$config = $config;
    }

    public static function disarm(): void
    {
        self::$config = null;
    }

    public static function config(): ?ReminderCollisionConfig
    {
        return self::$config;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new ReminderCollisionDriver($driver);
    }
}

final class ReminderCollisionDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new ReminderCollisionConnection(parent::connect($params));
    }
}

final class ReminderCollisionConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): DriverStatement
    {
        $statement = parent::prepare($sql);

        // The due-reminder scan only: a plain SELECT, never the refresh's
        // locking by-id read, so the two can never be mismatched.
        if (!preg_match('/SELECT.*FROM alert_incident.*next_reminder_at\s*<=/is', $sql) || str_contains(strtoupper($sql), 'FOR UPDATE')) {
            return $statement;
        }

        return new ReminderCollisionStatement($statement);
    }
}

final class ReminderCollisionStatement extends AbstractStatementMiddleware
{
    public function execute(): DriverResult
    {
        $result = parent::execute();

        $config = ReminderCollisionMiddleware::config();
        if (null !== $config && $config->armed) {
            $config->armed = false;

            // Committed on a separate connection after the handler's own
            // due-reminder read already returned the pre-resolution row;
            // only its later locking refresh() can observe this resolution.
            $pdo = new \PDO($config->pdoDsn, $config->pdoUser, $config->pdoPassword);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->prepare('UPDATE alert_incident SET status = ?, resolved_at = NOW() WHERE id = ?')
                ->execute([AlertIncident::STATUS_RESOLVED, $config->incidentId]);
        }

        return $result;
    }
}

/**
 * Configuration for one deterministic reminder-collision injection.
 */
final class ReminderCollisionConfig
{
    public bool $armed = true;

    public function __construct(
        public readonly int $incidentId,
        public readonly string $pdoDsn,
        public readonly string $pdoUser,
        public readonly string $pdoPassword,
    ) {
    }
}
