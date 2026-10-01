<?php

declare(strict_types=1);

namespace App\Watering;

use App\Entity\Device;
use App\Entity\WateringProposal;
use App\Entity\WateringProposalState;
use App\Repository\DeviceRepository;
use App\Repository\MeasurementRepository;
use App\Repository\WateringProposalRepository;
use App\Repository\WateringProposalStateRepository;
use App\Repository\WateringRunRepository;

/** The database owns the dry episode and decision; no sensor event directly starts watering. */
final class ProposalPolicy
{
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';

    private const DEVICE_NAME = 'SE01-Avocado';
    private const READINGS = 3;
    private const DAY_SECONDS = 86400;
    /** One initial prompt and one reminder per actuator, until a wet reading resets the episode. */
    private const MAX_PROMPTS = 2;

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly MeasurementRepository $measurements,
        private readonly WateringRunRepository $runs,
        private readonly WateringProposalRepository $proposals,
        private readonly WateringProposalStateRepository $states,
        private readonly TransactionRunner $runner,
        private readonly WateringManager $watering,
        private readonly float $threshold,
        private readonly int $freshnessMinutes,
        private readonly int $maxGapMinutes,
        private readonly int $validityMinutes,
        private readonly int $durationSeconds,
        private readonly string $actuatorTopic,
    ) {
        if (!is_finite($threshold) || $threshold <= 0 || $threshold > 100 || $freshnessMinutes < 1 || $maxGapMinutes < 1 || $validityMinutes < 1 || $durationSeconds < 1 || $durationSeconds > $watering->maxSeconds() || '' === trim($actuatorTopic)) {
            throw new \LogicException('Invalid dev watering proposal configuration.');
        }
    }

    /** Returns a newly persisted proposal, or null. Serializes evaluation by device row. */
    public function evaluate(): ?WateringProposal
    {
        return $this->runner->run(fn (): ?WateringProposal => $this->evaluateLocked());
    }

    public function claimNotification(int $id): bool
    {
        return WateringProposal::isValidId($id)
            && $this->proposals->claimNotification($id, $this->actuatorTopic, UtcClock::now());
    }

    /**
     * Only unclaimed, still actionable proposals may be sent after a restart.
     *
     * @return list<WateringProposal>
     */
    public function newNotifications(): array
    {
        return $this->proposals->newNotifications($this->actuatorTopic, UtcClock::now());
    }

    public function recordMessage(int $id, int $messageId): void
    {
        if (WateringProposal::isValidId($id)) {
            $this->proposals->recordMessage($id, $messageId);
        }
    }

    public function notificationUncertain(int $id): void
    {
        if (WateringProposal::isValidId($id)) {
            $this->proposals->markNotificationUncertain($id);
        }
    }

    /** Called only after exact user and private-chat authorization. */
    public function decide(int $id, string $action): string
    {
        if (!WateringProposal::isValidId($id) || !in_array($action, [self::ACTION_APPROVE, self::ACTION_REJECT], true)) {
            return ProposalDecisionResult::Ignored->value;
        }
        $claimed = $this->runner->run(fn (): string => $this->claimDecision($id, $action));

        return ProposalDecisionResult::Executing->value === $claimed ? $this->execute($id) : $claimed;
    }

    public function expire(): void
    {
        $this->proposals->expireDue(UtcClock::now());
        $this->proposals->interruptExecuting();
    }

    /** @return list<WateringProposal> */
    public function finalMessages(): array
    {
        return $this->proposals->finalMessages();
    }

    public function markMessageFinal(int $id): void
    {
        if (WateringProposal::isValidId($id)) {
            $this->proposals->markFinal($id);
        }
    }

    private function evaluateLocked(): ?WateringProposal
    {
        $device = $this->devices->lockByName(self::DEVICE_NAME);
        if (null === $device) {
            return null;
        }
        $deviceId = (int) $device->getId();
        $now = UtcClock::now();
        $this->proposals->expireDue($now, $deviceId);
        $this->proposals->invalidateOtherActuators($deviceId, $this->actuatorTopic);
        $readings = $this->measurements->latestSoilMoisture($deviceId, self::READINGS);
        if ([] !== $readings && $readings[0]['value'] >= $this->threshold) {
            $this->states->resetEpisode($deviceId);
            $this->proposals->invalidatePending($deviceId, 'Sensor recovered');

            return null;
        }
        if (!$this->dryReadings($readings, $now)) {
            return null;
        }
        $attempt = $this->runs->latest()?->getRequestedAt();
        if (null !== $attempt && $attempt->getTimestamp() > $now->getTimestamp() - self::DAY_SECONDS) {
            $this->proposals->invalidatePending($deviceId, 'Newer watering attempt');

            return null;
        }

        return $this->proposals->hasPending($deviceId) ? null : $this->prompt($device, $now, $readings, $attempt);
    }

    /**
     * @param list<array{value: float, measuredAt: \DateTimeImmutable}> $readings
     */
    private function prompt(Device $device, \DateTimeImmutable $now, array $readings, ?\DateTimeImmutable $attempt): ?WateringProposal
    {
        $state = $this->states->lockOrCreate($device, $this->actuatorTopic);
        if (!$this->mayPrompt($state, $now)) {
            return null;
        }
        $proposal = $this->createProposal($device, $now, $readings, $attempt);
        $state->setPromptCount($state->getPromptCount() + 1)->setLastPromptAt($now);

        return $proposal;
    }

    private function mayPrompt(WateringProposalState $state, \DateTimeImmutable $now): bool
    {
        $count = $state->getPromptCount();
        $last = $state->getLastPromptAt();
        if ($count >= self::MAX_PROMPTS) {
            return false;
        }

        return 0 === $count || null === $last || $last->getTimestamp() <= $now->getTimestamp() - self::DAY_SECONDS;
    }

    /**
     * @param list<array{value: float, measuredAt: \DateTimeImmutable}> $readings
     */
    private function createProposal(Device $device, \DateTimeImmutable $now, array $readings, ?\DateTimeImmutable $attempt): WateringProposal
    {
        $snapshot = array_reverse(array_map(
            static fn (array $r): array => ['value' => (float) $r['value'], 'measured_at' => $r['measuredAt']->format('Y-m-d H:i:s')],
            array_slice($readings, 0, self::READINGS),
        ));
        $proposal = (new WateringProposal())
            ->setDevice($device)
            ->setStatus(WateringProposalStatus::Pending->value)
            ->setCreatedAt($now)
            ->setExpiresAt($now->modify(sprintf('+%d minutes', $this->validityMinutes)))
            ->setDurationSeconds($this->durationSeconds)
            ->setReadingsJson($snapshot)
            ->setLastAttemptAt($attempt)
            ->setNotificationStatus(WateringNotificationStatus::New->value)
            ->setActuatorTopic($this->actuatorTopic);
        $this->proposals->add($proposal);

        return $proposal;
    }

    private function claimDecision(int $id, string $action): string
    {
        $p = $this->proposals->lockById($id);
        if (null === $p || WateringProposalStatus::Pending->value !== $p->getStatus()) {
            return ProposalDecisionResult::Ignored->value;
        }
        $now = UtcClock::now();
        $p->setDecidedAt($now);
        if ($p->getExpiresAt() <= $now) {
            $p->setStatus(WateringProposalStatus::Expired->value);

            return ProposalDecisionResult::Expired->value;
        }
        // Checked before any run is reserved; a legacy NULL topic never matches.
        if ($p->getActuatorTopic() !== $this->actuatorTopic) {
            $p->setStatus(WateringProposalStatus::Invalidated->value)->setFailure('Actuator changed');

            return ProposalDecisionResult::Invalidated->value;
        }
        if (self::ACTION_REJECT === $action) {
            $p->setStatus(WateringProposalStatus::Rejected->value);

            return ProposalDecisionResult::Rejected->value;
        }
        // A committed claim is deliberately terminal after a crash. Never automatically reissue request().
        $p->setStatus(WateringProposalStatus::Executing->value);

        return ProposalDecisionResult::Executing->value;
    }

    private function execute(int $id): string
    {
        try {
            $duration = (int) $this->proposals->fresh($id)?->getDurationSeconds();
            $runId = $this->watering->request($duration, fn (\DateTimeImmutable $now) => $this->assertStillValid($id, $now));
            $this->proposals->finishExecution($id, WateringProposalStatus::Approved->value, null, $runId);

            return ProposalDecisionResult::Approved->value;
        } catch (\DomainException|\LogicException $e) {
            $this->proposals->finishExecution(
                $id,
                WateringProposalStatus::Failed->value,
                substr($e->getMessage(), 0, WateringProposal::FAILURE_MAX_LENGTH),
                null,
            );

            return ProposalDecisionResult::Failed->value;
        } catch (\Throwable) {
            // Includes DB or MQTT uncertainty. The manager's reservation, if any, remains blocked.
            $this->proposals->finishExecution($id, WateringProposalStatus::Uncertain->value, 'Execution outcome uncertain; manual review required', null);

            return ProposalDecisionResult::Uncertain->value;
        }
    }

    /** Runs under the control lock, immediately before the run is reserved. */
    private function assertStillValid(int $id, \DateTimeImmutable $now): void
    {
        $p = $this->proposals->fresh($id);
        if (null === $p || WateringProposalStatus::Executing->value !== $p->getStatus() || $p->getExpiresAt() <= $now) {
            throw new \DomainException('Proposal expired or changed.');
        }
        $readings = $this->measurements->latestSoilMoisture((int) $p->getDevice()?->getId(), self::READINGS);
        if (!$this->dryReadings($readings, $now)) {
            throw new \DomainException('Sensor readings no longer support watering.');
        }
        $attempt = $this->runs->latest()?->getRequestedAt();
        if (null !== $attempt && ($attempt > $p->getCreatedAt() || $attempt->getTimestamp() > $now->getTimestamp() - self::DAY_SECONDS)) {
            throw new \DomainException('A newer or recent watering attempt exists.');
        }
    }

    /**
     * @param list<array{value: float, measuredAt: \DateTimeImmutable}> $r newest first
     */
    private function dryReadings(array $r, \DateTimeImmutable $now): bool
    {
        if (self::READINGS !== count($r)) {
            return false;
        }
        $times = array_map(static fn (array $row): int => $row['measuredAt']->getTimestamp(), $r);
        if ($times[0] > $now->getTimestamp() || $now->getTimestamp() - $times[0] > $this->freshnessMinutes * 60) {
            return false;
        }
        for ($i = 0; $i < self::READINGS; ++$i) {
            $value = (float) $r[$i]['value'];
            if (!is_finite($value) || $value >= $this->threshold || $value < 0 || $value > 100) {
                return false;
            }
            if ($i > 0 && ($times[$i - 1] <= $times[$i] || $times[$i - 1] - $times[$i] > $this->maxGapMinutes * 60)) {
                return false;
            }
        }

        return true;
    }
}
