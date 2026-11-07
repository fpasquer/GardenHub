<?php

declare(strict_types=1);

namespace App\Watering;

/**
 * Reads the alarm and battery indicators of one pump state report.
 * Anything that is not explicitly reported as healthy stays unconfirmed.
 */
final class ActuatorSafety
{
    /** Used only for pumps that publish no battery_low flag. */
    public const LOW_BATTERY_PERCENT = 20;

    /**
     * True only when the alarm and the battery are explicitly healthy.
     *
     * @param array<string, mixed> $state
     */
    public static function isConfirmedSafe(array $state): bool
    {
        return false === ($state['alarm_1'] ?? null)
            && false === self::isBatteryLow($state);
    }

    /**
     * True when a reported indicator requires the pump to stop.
     *
     * @param array<string, mixed> $state
     */
    public static function isUnsafe(array $state): bool
    {
        return true === ($state['alarm_1'] ?? false)
            || true === self::isBatteryLow($state);
    }

    /**
     * The pump's own flag wins. The ThirdReality kit never publishes one, so
     * its battery percentage is used instead. Null means unknown.
     *
     * @param array<string, mixed> $state
     */
    private static function isBatteryLow(array $state): ?bool
    {
        if (array_key_exists('battery_low', $state)) {
            return is_bool($state['battery_low'])
                ? $state['battery_low']
                : null;
        }
        $battery = $state['battery'] ?? null;
        if (!is_int($battery) && !is_float($battery)) {
            return null;
        }
        if ($battery < 0 || $battery > 100) {
            return null;
        }

        return $battery <= self::LOW_BATTERY_PERCENT;
    }
}
