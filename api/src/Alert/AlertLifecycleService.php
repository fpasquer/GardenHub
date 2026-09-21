<?php

namespace App\Alert;

use App\Alert\Message\SendTelegramNotification;
use App\Entity\AlertEvaluationProgress;
use App\Entity\AlertIncident;
use App\Repository\AlertEvaluationProgressRepository;
use App\Repository\AlertIncidentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Generic alert-incident lifecycle: open on first breach, confirm/advance
 * on repeated breach signals, resolve on repeated recovery signals. Knows
 * nothing about sensors, measurements or Telegram formatting; those are
 * handled by callers (ThresholdRuleEvaluator, InvalidReadingAlertService,
 * RecordProcessingFailureAlertHandler) and SendTelegramNotificationHandler.
 *
 * Replay/ordering protection is durable, stored in alert_evaluation_progress
 * (not on the open incident), so it survives incident resolution and the
 * no-open-incident periods before the first breach. For signals carrying a
 * measurementId, a signal is skipped when its id was already applied, or
 * when its measured_at is strictly older than the applied watermark — a
 * reading ingested later gets a higher id, so ids alone do not establish
 * measurement-time order. Signals with an equal measured_at are considered
 * (same-timestamp readings apply in arrival order). Everything below —
 * progress update, incident change and notification enqueue — commits in
 * one transaction.
 */
final class AlertLifecycleService
{
    public function __construct(
        private readonly AlertIncidentRepository $incidentRepository,
        private readonly AlertEvaluationProgressRepository $progressRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly int $reminderIntervalSeconds,
    ) {
    }

    /**
     * Applies one signal for (alertType, subjectKey). A crash between the
     * DB commit below and returning here is safe: nothing to resume, the
     * next real signal simply continues from the persisted incident state.
     */
    public function openOrAdvance(
        string $alertType,
        string $subjectKey,
        AlertSignal $signal,
        int $confirmationThreshold,
        int $recoveryThreshold,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($alertType, $subjectKey, $signal, $confirmationThreshold, $recoveryThreshold): void {
                // Lock progress first: every writer takes the progress row
                // before the incident row, so lock order is uniform.
                $progress = $this->progressRepository->lockOrCreate($alertType, $subjectKey);

                if ($this->alreadyConsidered($progress, $signal)) {
                    return;
                }
                $this->recordProgress($progress, $signal);

                $incident = $this->incidentRepository->lockOpenIncident($alertType, $subjectKey);
                if (null === $incident) {
                    if ($signal->breach) {
                        $this->create($alertType, $subjectKey, $confirmationThreshold, $signal);
                    }

                    return;
                }

                $signal->breach
                    ? $this->confirm($incident, $confirmationThreshold, $signal)
                    : $this->recover($incident, $recoveryThreshold, $signal);
            }
        );
    }

    private function alreadyConsidered(AlertEvaluationProgress $progress, AlertSignal $signal): bool
    {
        if (null === $signal->measurementId) {
            return false;
        }

        if ($signal->measurementId <= ($progress->getLastConsideredMeasurementId() ?? 0)) {
            return true;
        }

        $watermark = $progress->getLastConsideredMeasuredAt();

        return null !== $signal->measuredAt && null !== $watermark && $signal->measuredAt < $watermark;
    }

    private function recordProgress(AlertEvaluationProgress $progress, AlertSignal $signal): void
    {
        if (null === $signal->measurementId) {
            return;
        }

        $progress->recordConsidered($signal->measurementId, $signal->measuredAt, $this->clock->now());
        $this->entityManager->flush();
    }

    private function create(string $alertType, string $subjectKey, int $confirmationThreshold, AlertSignal $signal): void
    {
        $now = $this->clock->now();
        $incident = new AlertIncident();
        $incident->setAlertType($alertType)->setSubjectKey($subjectKey)
            ->setStatus(AlertIncident::STATUS_PENDING)
            ->setConfirmationCount(1)->setRecoveryCount(0)
            ->setFirstDetectedAt($now)->setLastEvaluatedAt($now);
        $this->applySignal($incident, $signal, $now);

        $activated = 1 >= $confirmationThreshold;
        if ($activated) {
            $incident->setStatus(AlertIncident::STATUS_ACTIVE)->setOpenedNotifiedAt($now)->setNextReminderAt($this->nextReminderAt($now));
        }

        $this->entityManager->persist($incident);
        $this->entityManager->flush();

        if ($activated) {
            $this->dispatchNotification($alertType, $subjectKey, 'opened', $now, $signal);
        }
    }

    private function confirm(AlertIncident $incident, int $confirmationThreshold, AlertSignal $signal): void
    {
        $now = $this->clock->now();
        $incident->setConfirmationCount($incident->getConfirmationCount() + 1)->setRecoveryCount(0);
        $this->applySignal($incident, $signal, $now);

        $justActivated = AlertIncident::STATUS_PENDING === $incident->getStatus()
            && $incident->getConfirmationCount() >= $confirmationThreshold;
        if ($justActivated) {
            $incident->setStatus(AlertIncident::STATUS_ACTIVE)->setOpenedNotifiedAt($now)->setNextReminderAt($this->nextReminderAt($now));
        }

        $this->entityManager->flush();

        if ($justActivated) {
            $this->dispatchNotification($incident->getAlertType(), $incident->getSubjectKey(), 'opened', $now, $signal);
        }
    }

    private function recover(AlertIncident $incident, int $recoveryThreshold, AlertSignal $signal): void
    {
        $now = $this->clock->now();
        $incident->setRecoveryCount($incident->getRecoveryCount() + 1)->setConfirmationCount(0);
        $this->applySignal($incident, $signal, $now);

        $wasPending = AlertIncident::STATUS_PENDING === $incident->getStatus();
        $justRecovered = AlertIncident::STATUS_ACTIVE === $incident->getStatus()
            && $incident->getRecoveryCount() >= $recoveryThreshold;

        if ($wasPending) {
            $incident->setStatus(AlertIncident::STATUS_RESOLVED)->setResolvedAt($now)
                ->setResolutionReason('Recovered before the confirmation threshold was reached.');
        } elseif ($justRecovered) {
            $incident->setStatus(AlertIncident::STATUS_RESOLVED)->setResolvedAt($now)->setRecoveryNotifiedAt($now);
        }

        $this->entityManager->flush();

        if ($justRecovered) {
            $this->dispatchNotification($incident->getAlertType(), $incident->getSubjectKey(), 'resolved', $now, $signal);
        }
    }

    private function nextReminderAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', $this->reminderIntervalSeconds));
    }

    private function applySignal(AlertIncident $incident, AlertSignal $signal, \DateTimeImmutable $now): void
    {
        if (null !== $signal->value) {
            $incident->setLastValue($signal->value)->setLastValueUnit($signal->unit);
        }
        if (null !== $signal->measuredAt) {
            $incident->setLastMeasuredAt($signal->measuredAt);
        }
        if (null !== $signal->measurementId) {
            $incident->setLastConsideredMeasurementId($signal->measurementId);
        }
        $incident->setLastEvaluatedAt($now)->touch($now);
    }

    private function dispatchNotification(string $alertType, string $subjectKey, string $event, \DateTimeImmutable $now, AlertSignal $signal): void
    {
        $this->messageBus->dispatch(new SendTelegramNotification(
            alertType: $alertType,
            subjectKey: $subjectKey,
            event: $event,
            occurredAt: $now,
            value: $signal->value,
            unit: $signal->unit,
            context: $signal->context,
        ));
    }
}
