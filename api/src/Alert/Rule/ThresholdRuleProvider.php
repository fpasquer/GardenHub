<?php

namespace App\Alert\Rule;

/**
 * Resolves the enabled threshold rules for a given measurement type and
 * device, merging the declarative defaults from gardenhub_alerts.yaml with
 * any per-devEui override.
 */
final class ThresholdRuleProvider
{
    /**
     * @param array<string, array{enabled: bool, measurement_type: string, comparison: string, threshold: float, confirmation_count: int, recovery_count: int, max_measurement_age_seconds: int}> $defaults
     * @param array<string, array<string, array<string, mixed>>>                                                                                                                                    $overrides
     */
    public function __construct(
        private readonly array $defaults,
        private readonly array $overrides,
    ) {
    }

    /** @return list<ThresholdRule> */
    public function rulesForMeasurementType(string $measurementType, string $devEui): array
    {
        $rules = [];
        foreach ($this->defaults as $alertType => $config) {
            if ($config['measurement_type'] !== $measurementType) {
                continue;
            }

            $merged = array_merge($config, $this->overrides[$devEui][$alertType] ?? []);
            if (!$merged['enabled']) {
                continue;
            }

            $rules[] = $this->toRule($alertType, $merged);
        }

        return $rules;
    }

    /** @param array<string, mixed> $config */
    private function toRule(string $alertType, array $config): ThresholdRule
    {
        return new ThresholdRule(
            alertType: $alertType,
            measurementType: (string) $config['measurement_type'],
            comparison: (string) $config['comparison'],
            threshold: (float) $config['threshold'],
            confirmationCount: (int) $config['confirmation_count'],
            recoveryCount: (int) $config['recovery_count'],
            maxMeasurementAgeSeconds: (int) $config['max_measurement_age_seconds'],
        );
    }
}
