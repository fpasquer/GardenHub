<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

use App\Mqtt\ChirpStackUplink;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Test-only Messenger event hooks recording the collision scenario's exact
 * sequence (see ScenarioRecorder) and, only once event A succeeds, enqueuing
 * event B on the bus so it is processed by the SAME running worker - no
 * manual EntityManager replacement, kernel reboot, or worker restart.
 *
 * A no-op unless armed via arm(), so it stays silent for every other
 * lifecycle scenario sharing this kernel's event dispatcher.
 */
final class ScenarioSubscriber implements EventSubscriberInterface
{
    private ?string $armedDeduplicationId = null;
    private ?array $eventBPayload = null;
    private bool $bEnqueued = false;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function arm(string $aDeduplicationId, string $eventBPayloadJson): void
    {
        $this->armedDeduplicationId = $aDeduplicationId;
        $this->eventBPayload = json_decode($eventBPayloadJson, true, 512, JSON_THROW_ON_ERROR);
        $this->bEnqueued = false;
    }

    public function disarm(): void
    {
        $this->armedDeduplicationId = null;
        $this->eventBPayload = null;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (null === $this->armedDeduplicationId || !$this->matches($event->getEnvelope()->getMessage(), $this->armedDeduplicationId)) {
            return;
        }

        if ($this->isUniqueConstraintViolation($event->getThrowable())) {
            ScenarioRecorder::record('a_flush_failed_unique_violation');
        }
        // clear() (issued by DoctrineClearEntityManagerWorkerSubscriber on this
        // same event) never un-closes an EM; only the later WorkerRunningEvent
        // reset replaces it, so this always observes the pre-reset state.
        if (!$this->entityManager->isOpen()) {
            ScenarioRecorder::record('a_em_closed_after_failed_flush');
        }
    }

    public function onMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        // Confirms Messenger actually re-sent the envelope, not just that
        // WorkerMessageFailedEvent::willRetry() was true.
        if (null !== $this->armedDeduplicationId && $this->matches($event->getEnvelope()->getMessage(), $this->armedDeduplicationId)) {
            ScenarioRecorder::record('a_retry_scheduled');
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if (null === $this->armedDeduplicationId) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if ($this->matches($message, $this->armedDeduplicationId)) {
            ScenarioRecorder::record('a_retry_succeeded');
            $this->enqueueEventB();

            return;
        }

        if ($this->bEnqueued && $this->matches($message, (string) $this->eventBPayload['deduplicationId'])) {
            ScenarioRecorder::record('b_succeeded');
        }
    }

    private function enqueueEventB(): void
    {
        if ($this->bEnqueued || null === $this->eventBPayload) {
            return;
        }

        $payload = $this->eventBPayload;
        $this->bus->dispatch(new ChirpStackUplink(
            $payload['deviceInfo']['devEui'],
            $payload['object'],
            new \DateTimeImmutable($payload['time']),
            $payload['deduplicationId'],
            $payload['deviceInfo']['deviceName'] ?? null,
        ));
        $this->bEnqueued = true;
        ScenarioRecorder::record('b_enqueued');
    }

    private function matches(object $message, string $deduplicationId): bool
    {
        return $message instanceof ChirpStackUplink && $message->deduplicationId === $deduplicationId;
    }

    private function isUniqueConstraintViolation(\Throwable $throwable): bool
    {
        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $wrapped) {
                if ($this->isUniqueConstraintViolation($wrapped)) {
                    return true;
                }
            }

            return false;
        }

        return $throwable instanceof UniqueConstraintViolationException
            || str_contains($throwable->getMessage(), 'uniq_measurement_dedup_type')
            || str_contains($throwable->getMessage(), 'Duplicate entry');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 200: must run before SendFailedMessageForRetryListener
            // (priority 100), which nested-dispatches WorkerMessageRetriedEvent.
            WorkerMessageFailedEvent::class => ['onMessageFailed', 200],
            WorkerMessageRetriedEvent::class => 'onMessageRetried',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
        ];
    }
}
