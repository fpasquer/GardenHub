<?php

namespace App\Alert\Rule;

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Entity\Measurement;
use App\Entity\Sensor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Translates one persisted Measurement into threshold-rule alert signals.
 *
 * Freshness gate: a measurement is evaluated only when it is not stale
 * relative to the injected clock — skipped when its age is strictly greater
 * than the rule's max_measurement_age_seconds (age == max is still fresh,
 * an inclusive boundary), and skipped when it is future-dated
 * (measured_at > now), so a bogus device clock can never drag the
 * evaluation watermark into the future and suppress subsequent real
 * readings. Skipped measurements remain stored; they simply never advance
 * threshold confirmation, recovery, or the evaluation progress.
 */
final class ThresholdRuleEvaluator
{
    public function __construct(
        private readonly ThresholdRuleProvider $ruleProvider,
        private readonly AlertLifecycleService $lifecycle,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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
        $measuredAt = $measurement->getMeasuredAt();
        $now = $this->clock->now();
        $age = $now->getTimestamp() - $measuredAt->getTimestamp();
        if ($age < 0 || $age > $rule->maxMeasurementAgeSeconds) {
            $this->logger->debug('Skipping stale or future-dated measurement for alert evaluation.', [
                'alertType' => $rule->alertType,
                'measurementId' => $measurement->getId(),
                'ageSeconds' => $age,
                'maxMeasurementAgeSeconds' => $rule->maxMeasurementAgeSeconds,
            ]);

            return;
        }

        $signal = new AlertSignal(
            breach: $rule->isBreached($measurement->getValue()),
            value: $measurement->getValue(),
            unit: $sensor->getUnit(),
            measuredAt: $measuredAt,
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
