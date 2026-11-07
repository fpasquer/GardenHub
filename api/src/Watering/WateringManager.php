<?php

declare(strict_types=1);

namespace App\Watering;

use App\Entity\WateringControl;
use App\Entity\WateringRun;
use App\Entity\WateringStage;
use App\Repository\WateringControlRepository;
use App\Repository\WateringRunRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/** Durable, single-actuator safety gate for explicitly enabled watering. */
final class WateringManager
{
    /** Absolute actuator bound; configured limits can only be stricter. */
    public const MAX_SECONDS = 1800;
    public const DEFAULT_MAX_SECONDS = 65;
    public const DEFAULT_DAILY_SECONDS = 130;
    public const DEFAULT_COOLDOWN_SECONDS = 60;
    public const STAGE_SECONDS = 65;
    public const STAGE_COUNT = 2;
    public const BREAK_SECONDS = 1800;

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
        private readonly WateringConfiguration $configuration,
        private readonly int $maxSeconds = self::DEFAULT_MAX_SECONDS,
        private readonly int $dailySeconds = self::DEFAULT_DAILY_SECONDS,
        private readonly int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
        private readonly ClockInterface $clock = new NativeClock('UTC'),
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

    public function assertProposalEligible(int $seconds, \DateTimeImmutable $now): void
    {
        $this->assertEnabled();
        if ($seconds < 1 || $seconds > $this->maxSeconds) {
            throw new \DomainException('Proposal duration exceeds watering limits.');
        }
        $this->assertReservable(
            $this->controls->lock(), $now, $seconds * self::STAGE_COUNT,
        );
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

        $this->publishStart($id, $seconds);

        return $id;
    }

    /** @param (callable(\DateTimeImmutable, WateringRun): void) $guard */
    public function requestCycle(string $topic, callable $guard): int
    {
        $this->assertEnabled();
        if ($topic !== $this->configuration->topic
            || self::STAGE_SECONDS > $this->maxSeconds) {
            throw new \DomainException('Automatic cycle exceeds duration limits or actuator changed.');
        }
        $id = $this->runner->run(
            fn (): int => $this->reserve(self::STAGE_SECONDS, $guard, true),
        );
        $this->publishStart($id, self::STAGE_SECONDS);

        return $id;
    }

    private function publishStart(int $id, int $seconds): void
    {
        // Claim is committed first. Uncertain delivery is never retried.
        try {
            $this->publisher->publish(['watering_times' => $seconds, 'state' => ActuatorState::On->value]);
        } catch (\Throwable $e) {
            $this->runs->markUncertainIfOpen($id, substr($e->getMessage(), 0, WateringRun::ERROR_MAX_LENGTH));

            throw new \RuntimeException(sprintf('Command delivery uncertain; cycle remains blocked: %d', $id), 0, $e);
        }

    }

    /** Returns true when the monitor must send OFF (alarm, battery or stray ON). */
    public function observe(string $payload, bool $retained = false): bool
    {
        $this->assertEnabled();
        $state = json_decode($payload, true);
        if (!is_array($state) || !in_array($state['state'] ?? null, [ActuatorState::On->value, ActuatorState::Off->value], true)) {
            return false;
        }

        return $this->runner->run(fn (): bool => $this->applyReport($state, $retained));
    }

    /** Marks overdue runs as timed out; they remain blocked until reviewed. */
    public function expire(): bool
    {
        $this->assertEnabled();

        return $this->runner->run(function (): bool {
            $run = $this->controls->lock()?->getActiveRun();
            if (null === $run || !$run->isOverdue($this->now())) {
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
        return $this->runs->requiresStop($this->configuration->topic);
    }

    public function heartbeat(): void
    {
        $this->assertEnabled();
        $this->controls->touchHeartbeat($this->now());
    }

    /** Clears the persisted heartbeat so a stale value can't authorize a request after a real failure. */
    public function invalidateHeartbeat(): void
    {
        $this->assertEnabled();
        $this->controls->clearHeartbeat();
        $this->runs->resetSafetyProbe();
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
            $run->setStatus(WateringRunStatus::Reviewed->value)->setFinishedAt($this->now());
            $this->cancelScheduledStages($run);
            $control->setActiveRun(null);
        });
    }

    private function reserve(int $seconds, ?callable $guard, bool $automatic = false): int
    {
        $control = $this->controls->lock();
        $now = $this->now();
        $total = $automatic ? $seconds * self::STAGE_COUNT : $seconds;
        $this->assertReservable($control, $now, $total);

        $run = (new WateringRun())
            ->setRequestedSeconds($total)
            ->setStatus(WateringRunStatus::Pending->value)
            ->setRequestedAt($now)
            // Includes a small allowance for ON acknowledgement and OFF report.
            ->setDeadlineAt($now->modify(sprintf('+%d seconds', $seconds + self::DEADLINE_ALLOWANCE_SECONDS)));
        if ($automatic) {
            $run->setActuatorTopic($this->configuration->topic);
            $first = (new WateringStage())->setPosition(1);
            $this->claimStage($first, $now);
            $run->addStage($first)->addStage(
                (new WateringStage())->setPosition(2),
            );
        }
        if (null !== $guard) {
            if ($automatic) {
                $guard($now, $run);
            } else {
                $guard($now);
            }
        }
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
        if (!$this->monitorReady($control, $now)) {
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
    private function applyReport(array $state, bool $retained): bool
    {
        $control = $this->controls->lock()
            ?? throw new \RuntimeException('Run watering migration before starting the monitor.');
        $run = $control->getActiveRun();
        $on = ActuatorState::On->value === $state['state'];
        if (null === $run) {
            return $on;
        }

        $now = $this->now();
        if (null !== $run->getActuatorTopic()
            && $run->getActuatorTopic() !== $this->configuration->topic) {
            $this->block($run, 'Actuator changed; verify the original pump manually.');

            return false;
        }
        if ($retained && null !== $run->getActuatorTopic()) {
            return $on;
        }
        $run->setLastState($state['state'])->setLastStateAt($now);
        $control->setActuatorSeenAt($now)
            ->setActuatorSafe(ActuatorSafety::isConfirmedSafe($state));

        // A missed deadline wins over any incoming report, even a plain OFF.
        if ($run->isOverdue($now)) {
            $this->markTimedOut($run);

            return true;
        }
        if (ActuatorSafety::isUnsafe($state) && $run->isOpen()) {
            $this->block($run, self::UNSAFE_REASON);

            return true;
        }

        if ($on && WateringRun::STATUS_BREAK === $run->getStatus()) {
            $this->block($run, 'Unexpected ON during the watering break.');

            return true;
        }

        return $on ? $this->applyOn($run, $now) : $this->applyOff($control, $run, $now);
    }

    private function applyOn(WateringRun $run, \DateTimeImmutable $now): bool
    {
        $stop = $run->isBlocked();
        if (WateringRunStatus::Pending->value === $run->getStatus()) {
            $run->setStatus(WateringRunStatus::Running->value);
            if (null === $run->getStartedAt()) {
                $run->setStartedAt($now);
            }
            $run->getCurrentStage()?->setStatus(WateringRun::STATUS_RUNNING)->setStartedAt($now);
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
            $stage = $run->getCurrentStage();
            $stage?->setStatus(WateringRun::STATUS_COMPLETED)->setFinishedAt($now);
            if (null !== $stage && 1 === $stage->getPosition()) {
                $run->setStatus(WateringRun::STATUS_BREAK)
                    ->setSecondStageDueAt($now->modify(sprintf('+%d seconds', self::BREAK_SECONDS)));

                return false;
            }
            $run->setStatus(WateringRunStatus::Completed->value)->setFinishedAt($now);
            $control->setActiveRun(null);
        }

        return false;
    }

    private function markTimedOut(WateringRun $run): void
    {
        $run->setStatus(WateringRunStatus::TimedOut->value)->setError(self::TIMEOUT_REASON);
        $run->getCurrentStage()?->setStatus(WateringRun::STATUS_TIMED_OUT);
    }

    private function block(WateringRun $run, string $reason): void
    {
        $run->setStatus(WateringRun::STATUS_UNCERTAIN)->setError($reason);
        $stage = $run->getCurrentStage();
        if (null !== $stage && WateringRun::STATUS_COMPLETED !== $stage->getStatus()) {
            $stage->setStatus(WateringRun::STATUS_UNCERTAIN);
        }
    }

    private function cancelScheduledStages(WateringRun $run): void
    {
        foreach ($run->getStages() as $stage) {
            if (null === $stage->getRequestedAt()) {
                $stage->setStatus(WateringRun::STATUS_CANCELLED);
            }
        }
    }

    private function claimStage(WateringStage $stage, \DateTimeImmutable $now): void
    {
        $stage->setStatus(WateringRun::STATUS_PENDING)->setRequestedAt($now)
            ->setDeadlineAt($now->modify(sprintf(
                '+%d seconds', self::STAGE_SECONDS + self::DEADLINE_ALLOWANCE_SECONDS,
            )));
    }

    private function monitorReady(WateringControl $control, \DateTimeImmutable $now): bool
    {
        $seen = $control->getMonitorSeenAt();

        return null !== $seen && $seen <= $now
            && $seen->getTimestamp() >= $now->getTimestamp() - self::MONITOR_FRESHNESS_SECONDS;
    }

    private function now(): \DateTimeImmutable
    {
        $zone = new \DateTimeZone('UTC');

        return new \DateTimeImmutable(
            $this->clock->now()->setTimezone($zone)->format('Y-m-d H:i:s'), $zone,
        );
    }

    /** Poll due work without sleeping; each ON claim commits before publication. */
    public function advanceCycle(): void
    {
        $this->assertEnabled();
        $command = $this->runner->run(fn (): ?array => $this->advanceLocked());
        if (null === $command) {
            return;
        }
        if ($command['start']) {
            $this->publishStart($command['id'], self::STAGE_SECONDS);

            return;
        }
        $this->publisher->publish(['state' => ActuatorState::Off->value]);
    }

    /** @return array{id: int, start: bool}|null */
    private function advanceLocked(): ?array
    {
        $control = $this->controls->lock();
        $run = $control?->getActiveRun();
        $now = $this->now();
        if (null === $run || WateringRun::STATUS_BREAK !== $run->getStatus()
            || null === $run->getSecondStageDueAt() || $run->getSecondStageDueAt() > $now) {
            return null;
        }
        if ($run->getActuatorTopic() !== $this->configuration->topic) {
            $this->block($run, 'Actuator changed; verify the original pump manually.');

            return null;
        }
        if (!$this->monitorReady($control, $now)) {
            return null;
        }
        if (null === $run->getSafetyProbeAt()) {
            // Earlier reports in this same second cannot confirm a new probe.
            $control->setActuatorSeenAt(null)->setActuatorSafe(null);
            $run->setSafetyProbeAt($now);

            return ['id' => (int) $run->getId(), 'start' => false];
        }
        if (!$this->confirmSafe($control, $run, $now)) {
            return null;
        }
        if (self::STAGE_SECONDS > $this->maxSeconds
            || $this->runs->sumRequestedSince($now->modify('-86400 seconds')) > $this->dailySeconds) {
            $run->setStatus(WateringRun::STATUS_CANCELLED)->setFinishedAt($now)
                ->setError('Remaining stage exceeds current watering limits.');
            $this->cancelScheduledStages($run);
            $control->setActiveRun(null);

            return null;
        }
        $stage = $run->getStages()->last();
        if (false === $stage || null !== $stage->getRequestedAt()) {
            throw new \LogicException('Missing or already claimed second watering stage.');
        }
        $this->claimStage($stage, $now);
        $control->setLastRequestAt($now);
        $run->setStatus(WateringRun::STATUS_PENDING)->setDeadlineAt($stage->getDeadlineAt());

        return ['id' => (int) $run->getId(), 'start' => true];
    }

    private function confirmSafe(WateringControl $control, WateringRun $run, \DateTimeImmutable $now): bool
    {
        $seen = $control->getActuatorSeenAt();
        $probe = $run->getSafetyProbeAt()
            ?? throw new \LogicException('A safety probe is required to confirm stage two.');
        $deadline = $probe->modify(sprintf(
            '+%d seconds', self::DEADLINE_ALLOWANCE_SECONDS,
        ));
        $fresh = null !== $seen && $seen >= $probe && $seen < $deadline
            && $seen <= $now
            && $seen->getTimestamp() >= $now->getTimestamp() - self::MONITOR_FRESHNESS_SECONDS;
        if (!$fresh) {
            if ($now >= $deadline) {
                $this->block($run, 'Fresh OFF and safety confirmation not received before stage two.');
            }

            return false;
        }
        if (ActuatorState::Off->value !== $run->getLastState() || true !== $control->isActuatorSafe()) {
            $this->block($run, 'Fresh OFF and safe alarm/battery indicators are required for stage two.');

            return false;
        }

        return true;
    }

    public function assertEnabled(): void
    {
        $this->configuration->assertAvailable();
    }
}
