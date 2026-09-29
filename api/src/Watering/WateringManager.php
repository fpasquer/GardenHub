<?php

declare(strict_types=1);

namespace App\Watering;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Durable, single-actuator safety gate for development watering. */
final class WateringManager
{
    /** Absolute actuator bound; configured limits can only be stricter. */
    public const MAX_SECONDS = 1800;
    public const DEFAULT_MAX_SECONDS = 30;
    public const DEFAULT_DAILY_SECONDS = 120;
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly WateringPublisher $publisher,
        private readonly bool $enabled,
        private readonly string $environment,
        private readonly int $maxSeconds = self::DEFAULT_MAX_SECONDS,
        private readonly int $dailySeconds = self::DEFAULT_DAILY_SECONDS,
        private readonly int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
    ) {
        if ($this->maxSeconds < 1 || $this->maxSeconds > self::MAX_SECONDS) {
            throw new \LogicException('Watering max duration must be between 1 and '.self::MAX_SECONDS.' seconds.');
        }
        if ($this->dailySeconds < $this->maxSeconds || $this->dailySeconds > 86400) {
            throw new \LogicException('Watering daily budget must be at least max duration and no more than 86400 seconds.');
        }
        if ($this->cooldownSeconds < 0 || $this->cooldownSeconds > 86400) {
            throw new \LogicException('Watering cooldown must be between 0 and 86400 seconds.');
        }
    }

    public function maxSeconds(): int
    {
        return $this->maxSeconds;
    }

    /** Optional guard runs under the same control lock as the run reservation. */
    public function request(int $seconds, ?callable $guard = null): string
    {
        $this->assertEnabled();
        if ($seconds < 1 || $seconds > $this->maxSeconds) {
            throw new \DomainException('Duration must be between 1 and '.$this->maxSeconds.' seconds.');
        }

        $id = (string) Uuid::v4();
        $this->db->transactional(function (Connection $db) use ($id, $seconds, $guard): void {
            $control = $db->fetchAssociative('SELECT * FROM watering_control WHERE id = 1 FOR UPDATE');
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!$control || $control['active_run_id'] !== null) {
                throw new \DomainException('A watering cycle is active or requires review.');
            }
            if ($control['monitor_seen_at'] === null || strtotime($control['monitor_seen_at'].' UTC') < $now->getTimestamp() - 5) {
                throw new \DomainException('Watering monitor is not reporting; refusing to start.');
            }
            if ($control['last_request_at'] !== null && strtotime($control['last_request_at'].' UTC') > $now->getTimestamp() - $this->cooldownSeconds) {
                throw new \DomainException('Watering cooldown has not elapsed.');
            }
            $used = (int) $db->fetchOne('SELECT COALESCE(SUM(requested_seconds), 0) FROM watering_run WHERE requested_at >= ?', [$now->modify('-24 hours')->format('Y-m-d H:i:s')]);
            if ($used + $seconds > $this->dailySeconds) {
                throw new \DomainException('Rolling 24-hour watering limit exceeded.');
            }
            if ($guard !== null) {
                $guard($db, $now);
            }

            $db->insert('watering_run', [
                'id' => $id,
                'requested_seconds' => $seconds,
                'status' => 'pending',
                'requested_at' => $now->format('Y-m-d H:i:s'),
                // Includes a small allowance for ON acknowledgement and OFF report.
                'deadline_at' => $now->modify('+'.($seconds + 10).' seconds')->format('Y-m-d H:i:s'),
            ]);
            $db->update('watering_control', ['active_run_id' => $id, 'last_request_at' => $now->format('Y-m-d H:i:s')], ['id' => 1]);
        });

        // The DB reservation is committed before publishing: a crash or
        // uncertain PUBACK leaves the cycle blocked, never available for retry.
        try {
            $this->publisher->publish(['watering_times' => $seconds, 'state' => 'ON']);
        } catch (\Throwable $e) {
            $this->db->executeStatement(
                "UPDATE watering_run SET status = 'uncertain', error = ? WHERE id = ? AND status IN ('pending', 'running')",
                [substr($e->getMessage(), 0, 255), $id],
            );
            throw new \RuntimeException('Command delivery uncertain; cycle remains blocked: '.$id, 0, $e);
        }

        return $id;
    }

    /** Returns true when the monitor must send OFF (alarm, battery or stray ON). */
    public function observe(string $payload): bool
    {
        $this->assertEnabled();
        $state = json_decode($payload, true);
        if (!is_array($state) || !in_array($state['state'] ?? null, ['ON', 'OFF'], true)) {
            return false;
        }
        $stop = false;
        $this->db->transactional(function (Connection $db) use ($state, &$stop): void {
            $control = $db->fetchAssociative('SELECT * FROM watering_control WHERE id = 1 FOR UPDATE');
            if (!$control) {
                throw new \RuntimeException('Run watering migration before starting the monitor.');
            }
            $id = $control['active_run_id'];
            $on = $state['state'] === 'ON';
            $unsafe = ($state['alarm_1'] ?? false) === true || ($state['battery_low'] ?? false) === true;
            if ($id === null) {
                $stop = $on;
                return;
            }
            $run = $db->fetchAssociative('SELECT * FROM watering_run WHERE id = ?', [$id]);
            if (!$run) {
                throw new \RuntimeException('Active watering run is missing.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->update('watering_run', ['last_state' => $state['state'], 'last_state_at' => $now], ['id' => $id]);

            // A missed deadline wins over any incoming report, even a plain OFF.
            if ($this->isOverdue($run, $now)) {
                $this->markTimedOut($db, $id, 'OFF not confirmed before deadline');
                $stop = true;
                return;
            }

            if ($unsafe && in_array($run['status'], ['pending', 'running'], true)) {
                $db->update('watering_run', ['status' => 'uncertain', 'error' => 'Water shortage or low battery reported'], ['id' => $id]);
                $stop = true;
                return;
            }

            if ($on) {
                $stop = in_array($run['status'], ['timed_out', 'uncertain'], true);
                if ($run['status'] === 'pending') {
                    $db->update('watering_run', ['status' => 'running', 'started_at' => $now], ['id' => $id]);
                }
            } elseif ($run['status'] === 'running' && $run['started_at'] !== null) {
                $db->update('watering_run', ['status' => 'completed', 'finished_at' => $now], ['id' => $id]);
                $db->update('watering_control', ['active_run_id' => null], ['id' => 1]);
            }
            // OFF without an observed ON could be a stale retained state;
            // never clear a pending, timed-out or uncertain cycle from it.
        });

        return $stop;
    }

    /** Marks overdue runs as timed out; they remain blocked until reviewed. */
    public function expire(): bool
    {
        $this->assertEnabled();
        return $this->db->transactional(function (Connection $db): bool {
            $control = $db->fetchAssociative('SELECT active_run_id FROM watering_control WHERE id = 1 FOR UPDATE');
            if (!$control || $control['active_run_id'] === null) {
                return false;
            }
            $run = $db->fetchAssociative('SELECT status, deadline_at FROM watering_run WHERE id = ?', [$control['active_run_id']]);
            if (!$run || !$this->isOverdue($run, gmdate('Y-m-d H:i:s'))) {
                return false;
            }
            $this->markTimedOut($db, $control['active_run_id'], 'OFF not confirmed before deadline');
            return true;
        });
    }

    /** Same timeout rule used by expire() and observe(): only a still-open cycle can be overdue. */
    private function isOverdue(array $run, string $now): bool
    {
        return in_array($run['status'], ['pending', 'running'], true) && $run['deadline_at'] <= $now;
    }

    private function markTimedOut(Connection $db, string $runId, string $reason): void
    {
        $db->update('watering_run', ['status' => 'timed_out', 'error' => $reason], ['id' => $runId]);
    }

    public function latest(): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM watering_run ORDER BY requested_at DESC, id DESC LIMIT 1');
        return $row ?: null;
    }

    /** Inspects the run actually referenced by active_run_id, never inferred by recency. */
    public function requiresStop(): bool
    {
        $status = $this->db->fetchOne(
            'SELECT r.status FROM watering_control c JOIN watering_run r ON r.id = c.active_run_id WHERE c.id = 1'
        );
        return in_array($status, ['timed_out', 'uncertain'], true);
    }

    public function heartbeat(): void
    {
        $this->assertEnabled();
        $this->db->update('watering_control', ['monitor_seen_at' => gmdate('Y-m-d H:i:s')], ['id' => 1]);
    }

    /** Clears the persisted heartbeat so a stale value can't authorize a request after a real failure. */
    public function invalidateHeartbeat(): void
    {
        $this->assertEnabled();
        $this->db->update('watering_control', ['monitor_seen_at' => null], ['id' => 1]);
    }

    /** Manual recovery only after independently verifying that the actuator is OFF. */
    public function acknowledgeStopped(): void
    {
        $this->assertEnabled();
        $this->db->transactional(function (Connection $db): void {
            $control = $db->fetchAssociative('SELECT active_run_id FROM watering_control WHERE id = 1 FOR UPDATE');
            if (!$control || $control['active_run_id'] === null) {
                throw new \DomainException('No blocked cycle to review.');
            }
            $run = $db->fetchAssociative('SELECT status FROM watering_run WHERE id = ?', [$control['active_run_id']]);
            if (!$run || !in_array($run['status'], ['timed_out', 'uncertain'], true)) {
                throw new \DomainException('Only a blocked cycle can be manually reviewed.');
            }
            $db->update('watering_run', ['status' => 'reviewed', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $control['active_run_id']]);
            $db->update('watering_control', ['active_run_id' => null], ['id' => 1]);
        });
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled || $this->environment !== 'dev') {
            throw new \LogicException('Watering control is available only in explicitly enabled development.');
        }
    }
}
