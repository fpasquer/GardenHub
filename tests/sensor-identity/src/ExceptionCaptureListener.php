<?php

declare(strict_types=1);

namespace Tests\SensorIdentity;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Test-only kernel.exception listener recording the real, unflattened
 * exception for the current request, so a scenario can assert on the exact
 * exception chain (e.g. a lock-wait timeout) instead of pattern-matching the
 * rendered HTTP error response body. Registered with a high priority so it
 * observes the original throwable before any other listener replaces it.
 *
 * Registered only by ConcurrencyKernel; production never registers this.
 */
final class ExceptionCaptureListener
{
    private ?\Throwable $captured = null;

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->captured = $event->getThrowable();
    }

    public function reset(): void
    {
        $this->captured = null;
    }

    public function captured(): ?\Throwable
    {
        return $this->captured;
    }
}
