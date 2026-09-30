<?php

declare(strict_types=1);

namespace App\Watering;

use App\Entity\WateringControl;
use App\Entity\WateringRun;
use App\Repository\WateringControlRepository;
use App\Repository\WateringRunRepository;

/** Durable, single-actuator safety gate for development watering. */
final class WateringManager
{
    /** Absolute actuator bound; configured limits can only be stricter. */
    public const MAX_SECONDS = 1800;
    public const DEFAULT_MAX_SECONDS = 30;
    public const DEFAULT_DAILY_SECONDS = 120;
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    private const TIMEOUT_REASON = 'OFF not confirmed before deadline';
    private const UNSAFE_REASON = 'Water shortage or low battery reported';
    private const MONITOR_FRESHNESS_SECONDS = 5;
    private const DEADLINE_ALLOWANCE_SECONDS = 10;
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly WateringControlRepository $controls,
        private readonly WateringRunRepository $runs,
        private readonly TransactionRunner $runner,
        private readonly InterfaceWateringPublisher $publisher,
        private readonly bool $enabled,
        private readonly string $environment,
        private readonly int $maxSeconds = self::DEFAULT_MAX_SECONDS,
        private readonly int $dailySeconds = self::DEFAULT_DAILY_SECONDS,
        private readonly int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
    ) {
        if ($this->maxSeconds < 1 || $this->maxSeconds > self::MAX_SECONDS) {
            throw new \LogicException(sprintf('Watering max duration must be between 1 and %d seconds.', self::MAX_SECONDS));
        }
        if ($this->dailySeconds < $this->maxSeconds || $this->dailySeconds > self::SECONDS_PER_DAY) {
            throw new \LogicException('Watering daily budget must be at least max duration and no more than 86400 seconds.');
        }
        if ($this->cooldownSeconds < 0 || $this->cooldownSeconds > self::SECONDS_PER_DAY) {
            throw new \LogicException('Watering cooldown must be between 0 and 86400 seconds.');
        }
    }

    public function maxSeconds(): int
    {
        return $this->maxSeconds;
    }

    /**
     * Optional guard runs under the same control lock as the run reservation.
     *
     * @param (callable(\DateTimeImmutable): void)|null $guard
     *
     * @return int the id of the reserved run
     */
    public function request(int $seconds, ?callable $guard = null): int
    {
        $this->assertEnabled();
        if ($seconds < 1 || $seconds > $this->maxSeconds) {
            throw new \DomainException(sprintf('Duration must be between 1 and %d seconds.', $this->maxSeconds));
        }

        $id = $this->runner->run(fn (): int => $this->reserve($seconds, $guard));

        // The DB reservation is committed before publishing: a crash or
        // uncertain PUBACK leaves the cycle blocked, never available for retry.
        try {
            $this->publisher->publish(['watering_times' => $seconds, 'state' => ActuatorState::On->value]);
        } catch (\Throwable $e) {
            $this->runs->markUncertainIfOpen($id, substr($e->getMessage(), 0, WateringRun::ERROR_MAX_LENGTH));

            throw new \RuntimeException(sprintf('Command delivery uncertain; cycle remains blocked: %d', $id), 0, $e);
        }

        return $id;
    }

    /** Returns true when the monitor must send OFF (alarm, battery or stray ON). */
    public function observe(string $payload): bool
    {
        $this->assertEnabled();
        $state = json_decode($payload, true);
        if (!is_array($state) || !in_array($state['state'] ?? null, [ActuatorState::On->value, ActuatorState::Off->value], true)) {
            return false;
        }

        return $this->runner->run(fn (): bool => $this->applyReport($state));
    }

    /** Marks overdue runs as timed out; they remain blocked until reviewed. */
    public function expire(): bool
    {
        $this->assertEnabled();

        return $this->runner->run(function (): bool {
            $run = $this->controls->lock()?->getActiveRun();
            if (null === $run || !$run->isOverdue(UtcClock::now())) {
                return false;
            }
            $this->markTimedOut($run);

            return true;
        });
    }

    public function latest(): ?WateringRun
    {
        return $this->runs->latest();
    }

    /** Inspects the run actually referenced by active_run_id, never inferred by recency. */
    public function requiresStop(): bool
    {
        return $this->runs->requiresStop();
    }

    public function heartbeat(): void
    {
        $this->assertEnabled();
        $this->controls->touchHeartbeat(UtcClock::now());
    }

    /** Clears the persisted heartbeat so a stale value can't authorize a request after a real failure. */
    public function invalidateHeartbeat(): void
    {
        $this->assertEnabled();
        $this->controls->clearHeartbeat();
    }

    /** Manual recovery only after independently verifying that the actuator is OFF. */
    public function acknowledgeStopped(): void
    {
        $this->assertEnabled();
        $this->runner->run(function (): void {
            $control = $this->controls->lock();
            $run = $control?->getActiveRun();
            if (null === $control || null === $run) {
                throw new \DomainException('No blocked cycle to review.');
            }
            if (!$run->isBlocked()) {
                throw new \DomainException('Only a blocked cycle can be manually reviewed.');
            }
            $run->setStatus(WateringRunStatus::Reviewed->value)->setFinishedAt(UtcClock::now());
            $control->setActiveRun(null);
        });
    }

    private function reserve(int $seconds, ?callable $guard): int
    {
        $control = $this->controls->lock();
        $now = UtcClock::now();
        $this->assertReservable($control, $now, $seconds);
        if (null !== $guard) {
            $guard($now);
        }

        $run = (new WateringRun())
            ->setRequestedSeconds($seconds)
            ->setStatus(WateringRunStatus::Pending->value)
            ->setRequestedAt($now)
            // Includes a small allowance for ON acknowledgement and OFF report.
            ->setDeadlineAt($now->modify(sprintf('+%d seconds', $seconds + self::DEADLINE_ALLOWANCE_SECONDS)));
        $this->runs->add($run);
        $control->setActiveRun($run)->setLastRequestAt($now);

        return (int) $run->getId();
    }

    /** @phpstan-assert WateringControl $control */
    private function assertReservable(?WateringControl $control, \DateTimeImmutable $now, int $seconds): void
    {
        if (null === $control || null !== $control->getActiveRun()) {
            throw new \DomainException('A watering cycle is active or requires review.');
        }
        $seen = $control->getMonitorSeenAt();
        if (null === $seen || $seen->getTimestamp() < $now->getTimestamp() - self::MONITOR_FRESHNESS_SECONDS) {
            throw new \DomainException('Watering monitor is not reporting; refusing to start.');
        }
        $last = $control->getLastRequestAt();
        if (null !== $last && $last->getTimestamp() > $now->getTimestamp() - $this->cooldownSeconds) {
            throw new \DomainException('Watering cooldown has not elapsed.');
        }
        $used = $this->runs->sumRequestedSince($now->modify(sprintf('-%d seconds', self::SECONDS_PER_DAY)));
        if ($used + $seconds > $this->dailySeconds) {
            throw new \DomainException('Rolling 24-hour watering limit exceeded.');
        }
    }

    /** @param array<string, mixed> $state */
    private function applyReport(array $state): bool
    {
        $control = $this->controls->lock()
            ?? throw new \RuntimeException('Run watering migration before starting the monitor.');
        $run = $control->getActiveRun();
        $on = ActuatorState::On->value === $state['state'];
        if (null === $run) {
            return $on;
        }

        $now = UtcClock::now();
        $run->setLastState($state['state'])->setLastStateAt($now);

        // A missed deadline wins over any incoming report, even a plain OFF.
        if ($run->isOverdue($now)) {
            $this->markTimedOut($run);

            return true;
        }
        if ($this->isUnsafe($state) && $run->isOpen()) {
            $run->setStatus(WateringRunStatus::Uncertain->value)->setError(self::UNSAFE_REASON);

            return true;
        }

        return $on ? $this->applyOn($run, $now) : $this->applyOff($control, $run, $now);
    }

    private function applyOn(WateringRun $run, \DateTimeImmutable $now): bool
    {
        $stop = $run->isBlocked();
        if (WateringRunStatus::Pending->value === $run->getStatus()) {
            $run->setStatus(WateringRunStatus::Running->value)->setStartedAt($now);
        }

        return $stop;
    }

    /**
     * OFF without an observed ON could be a stale retained state; never clear
     * a pending, timed-out or uncertain cycle from it.
     */
    private function applyOff(WateringControl $control, WateringRun $run, \DateTimeImmutable $now): bool
    {
        if (WateringRunStatus::Running->value === $run->getStatus() && null !== $run->getStartedAt()) {
            $run->setStatus(WateringRunStatus::Completed->value)->setFinishedAt($now);
            $control->setActiveRun(null);
        }

        return false;
    }

    /** @param array<string, mixed> $state */
    private function isUnsafe(array $state): bool
    {
        return true === ($state['alarm_1'] ?? false) || true === ($state['battery_low'] ?? false);
    }

    private function markTimedOut(WateringRun $run): void
    {
        $run->setStatus(WateringRunStatus::TimedOut->value)->setError(self::TIMEOUT_REASON);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled || 'dev' !== $this->environment) {
            throw new \LogicException('Watering control is available only in explicitly enabled development.');
        }
    }
}
