<?php

namespace App\Alert\Message;

use App\Entity\AlertIncident;
use App\Repository\AlertIncidentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends a reminder notification for every ACTIVE incident whose
 * next_reminder_at is due, then reschedules it. Duration/staleness-based
 * re-evaluation is not needed here: confirmation/recovery counts only ever
 * advance from new measurements (see ThresholdRuleEvaluator), which this
 * job does not have.
 */
#[AsMessageHandler]
final class EvaluateAlertsHandler
{
    public function __construct(
        private readonly AlertIncidentRepository $incidentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly int $reminderIntervalSeconds,
    ) {
    }

    public function __invoke(EvaluateAlertsMessage $message): void
    {
        $now = $this->clock->now();
        foreach ($this->incidentRepository->findDueForReminder($now) as $incident) {
            $this->sendReminder($incident, $now);
        }
    }

    private function sendReminder(AlertIncident $incident, \DateTimeImmutable $now): void
    {
        $this->entityManager->wrapInTransaction(function () use ($incident, $now): void {
            $incident->setLastReminderAt($now)
                ->setNextReminderAt($now->modify(sprintf('+%d seconds', $this->reminderIntervalSeconds)))
                ->touch($now);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new SendTelegramNotification(
                alertType: $incident->getAlertType(),
                subjectKey: $incident->getSubjectKey(),
                event: 'reminder',
                occurredAt: $now,
                value: $incident->getLastValue(),
                unit: $incident->getLastValueUnit(),
            ));
        });
    }
}
