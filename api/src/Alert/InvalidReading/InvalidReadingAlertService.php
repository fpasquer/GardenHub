<?php

namespace App\Alert\InvalidReading;

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Entity\Device;

/**
 * Tracks the invalid_reading alert per device: one incident per device at a
 * time, recording only the latest rejection reason. Deliberately does not
 * try to distinguish "recovered from reason A" vs "still failing for
 * reason B" — only overall per-device valid/invalid signals are naturally
 * available from the ingestion pipeline.
 */
final class InvalidReadingAlertService
{
    public function __construct(
        private readonly AlertLifecycleService $lifecycle,
        private readonly int $confirmationCount,
        private readonly int $recoveryCount,
    ) {
    }

    public function recordRejection(Device $device, ?\DateTimeImmutable $measuredAt, string $reason): void
    {
        $this->lifecycle->openOrAdvance(
            'invalid_reading',
            $this->subjectKey($device),
            new AlertSignal(breach: true, measuredAt: $measuredAt, context: $reason),
            $this->confirmationCount,
            $this->recoveryCount,
        );
    }

    public function recordValidReading(Device $device, \DateTimeImmutable $measuredAt): void
    {
        $this->lifecycle->openOrAdvance(
            'invalid_reading',
            $this->subjectKey($device),
            new AlertSignal(breach: false, measuredAt: $measuredAt),
            $this->confirmationCount,
            $this->recoveryCount,
        );
    }

    private function subjectKey(Device $device): string
    {
        return sprintf('device:%d', $device->getId());
    }
}
