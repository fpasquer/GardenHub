<?php

namespace App\Alert\Message;

/**
 * Durable request to run alert evaluation for a batch of measurements that
 * were just persisted by one ChirpStack uplink. Dispatched from inside the
 * same DB transaction as the measurement writes (see ChirpStackUplinkHandler),
 * so a crash before commit means neither the measurements nor this message
 * exist, and a crash after commit means both do.
 */
final readonly class EvaluateUplinkMeasurements
{
    /** @param list<int> $measurementIds */
    public function __construct(
        public array $measurementIds,
    ) {
    }
}
