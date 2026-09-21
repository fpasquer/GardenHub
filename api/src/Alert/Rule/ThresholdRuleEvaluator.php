<?php

namespace App\Alert\Rule;

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Entity\Measurement;
use App\Entity\Sensor;

/**
 * Translates one persisted Measurement into threshold-rule alert signals.
 * Freshness/staleness handling is not needed here: this only ever runs off
 * a measurement that was just received, never a stale re-check (that is
 * the periodic scheduler's job).
 */
final class ThresholdRuleEvaluator
{
    public function __construct(
        private readonly ThresholdRuleProvider $ruleProvider,
        private readonly AlertLifecycleService $lifecycle,
    ) {
    }

    public function evaluateMeasurement(Measurement $measurement): void
    {
        $sensor = $measurement->getSensor();
        $devEui = $sensor?->getDevice()?->getDevEui();
        if (null === $sensor || null === $devEui || null === $measurement->getType()) {
            return;
        }

        foreach ($this->ruleProvider->rulesForMeasurementType($measurement->getType(), $devEui) as $rule) {
            $this->applyRule($rule, $sensor, $measurement);
        }
    }

    private function applyRule(ThresholdRule $rule, Sensor $sensor, Measurement $measurement): void
    {
        $signal = new AlertSignal(
            breach: $rule->isBreached($measurement->getValue()),
            value: $measurement->getValue(),
            unit: $sensor->getUnit(),
            measuredAt: $measurement->getMeasuredAt(),
            measurementId: $measurement->getId(),
        );

        $this->lifecycle->openOrAdvance(
            $rule->alertType,
            sprintf('sensor:%d', $sensor->getId()),
            $signal,
            $rule->confirmationCount,
            $rule->recoveryCount,
        );
    }
}
