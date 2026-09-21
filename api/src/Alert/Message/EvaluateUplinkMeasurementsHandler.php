<?php

namespace App\Alert\Message;

use App\Alert\Rule\ThresholdRuleEvaluator;
use App\Repository\MeasurementRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Loads each measurement from the given batch and runs it through the
 * threshold-rule evaluator. Missing measurements (e.g. a very old replay
 * against a pruned dataset) are skipped rather than failing the batch.
 */
#[AsMessageHandler]
final class EvaluateUplinkMeasurementsHandler
{
    public function __construct(
        private readonly MeasurementRepository $measurementRepository,
        private readonly ThresholdRuleEvaluator $ruleEvaluator,
    ) {
    }

    public function __invoke(EvaluateUplinkMeasurements $message): void
    {
        foreach ($message->measurementIds as $measurementId) {
            $measurement = $this->measurementRepository->find($measurementId);
            if (null !== $measurement) {
                $this->ruleEvaluator->evaluateMeasurement($measurement);
            }
        }
    }
}
