<?php

declare(strict_types=1);

namespace App\Watering;

/** Application-side UTC clock; DQL has no UTC_TIMESTAMP(), and stored DATETIMEs are naive UTC. */
final class UtcClock
{
    /** Whole seconds, matching the precision of the stored DATETIME columns. */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }
}
