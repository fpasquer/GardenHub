<?php

namespace App\Alert;

/**
 * One breach/recovery signal fed into AlertLifecycleService::openOrAdvance().
 * Bundles the optional display/idempotency fields so callers don't have to
 * pass five loose nullable parameters around.
 */
final readonly class AlertSignal
{
    public function __construct(
        public bool $breach,
        public ?float $value = null,
        public ?string $unit = null,
        public ?\DateTimeImmutable $measuredAt = null,
        public ?int $measurementId = null,
        public ?string $context = null,
    ) {
    }
}
