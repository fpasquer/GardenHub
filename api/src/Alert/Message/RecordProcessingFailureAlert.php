<?php

namespace App\Alert\Message;

/**
 * Durable request to record (or advance) a processing_failure incident for
 * one ChirpStack uplink whose Messenger processing terminally failed
 * (retries exhausted). Dispatched from AlertProcessingFailureListener,
 * which cannot safely touch the ORM directly (see that class).
 */
final readonly class RecordProcessingFailureAlert
{
    public function __construct(
        public string $deduplicationId,
        public string $failureReason,
    ) {
    }
}
