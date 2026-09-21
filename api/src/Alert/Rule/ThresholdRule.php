<?php

namespace App\Alert\Rule;

/**
 * A single Symfony-owned plant/battery alert rule resolved for one sensor
 * (config defaults merged with any per-devEui override).
 */
final readonly class ThresholdRule
{
    public function __construct(
        public string $alertType,
        public string $measurementType,
        public string $comparison,
        public float $threshold,
        public int $confirmationCount,
        public int $recoveryCount,
        public int $maxMeasurementAgeSeconds,
    ) {
    }

    public function isBreached(float $value): bool
    {
        return 'below' === $this->comparison ? $value < $this->threshold : $value > $this->threshold;
    }
}
