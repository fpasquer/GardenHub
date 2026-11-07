<?php

declare(strict_types=1);

namespace App\Watering;

use Doctrine\DBAL\Connection;

final readonly class WateringReadiness
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertIdle(bool $allowUninitialized = false): void
    {
        if ($allowUninitialized
            && !$this->connection->createSchemaManager()->tablesExist(['watering_control'])) {
            return;
        }
        $control = $this->connection->fetchAssociative(
            'SELECT active_run_id FROM watering_control WHERE id = 1',
        );
        if (false === $control) {
            throw new \RuntimeException('Watering control is missing; deployment refused.');
        }
        if (null !== $control['active_run_id']) {
            throw new \RuntimeException(sprintf(
                'Watering cycle %d is unresolved; keep the monitor running and defer deployment.',
                $control['active_run_id'],
            ));
        }
    }

    public function assertMonitorReady(): void
    {
        $seen = $this->connection->fetchOne(
            'SELECT monitor_seen_at FROM watering_control WHERE id = 1',
        );
        if (!is_string($seen)) {
            throw new \RuntimeException('Watering monitor is not ready.');
        }
        $age = UtcClock::now()->getTimestamp()
            - (new \DateTimeImmutable($seen, new \DateTimeZone('UTC')))->getTimestamp();
        if ($age < 0 || $age > 5) {
            throw new \RuntimeException('Watering monitor heartbeat is stale.');
        }
    }
}