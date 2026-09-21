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
 * Also offers an independent failure-capture mode (armFailureCapture) used
 * by the retry-exhaustion scenario to prove, through the real consumer, that
 * every failed attempt for a given deduplicationId was actually caused by
 * the intended CHECK constraint rather than an unrelated error.
 *
 * A no-op unless armed via arm()/armFailureCapture(), so it stays silent for
 * every other lifecycle scenario sharing this kernel's event dispatcher.
 */
final class ScenarioSubscriber implements EventSubscriberInterface
{
    private ?string $armedDeduplicationId = null;
    private ?array $eventBPayload = null;
    private bool $bEnqueued = false;

    private ?string $captureDeduplicationId = null;
    private int $capturedFailureCount = 0;
    private int $capturedRetryCount = 0;
    /** @var list<string> */
    private array $capturedFailureMessages = [];

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

    /**
     * Starts recording, for the given deduplicationId only, every failed
     * processing attempt's flattened exception chain plus the number of
     * retries actually scheduled by Messenger.
     */
    public function armFailureCapture(string $deduplicationId): void
    {
        $this->captureDeduplicationId = $deduplicationId;
        $this->capturedFailureCount = 0;
        $this->capturedRetryCount = 0;
        $this->capturedFailureMessages = [];
    }

    public function disarmFailureCapture(): void
    {
        $this->captureDeduplicationId = null;
    }

    public function capturedFailureCount(): int
    {
        return $this->capturedFailureCount;
    }

    public function capturedRetryCount(): int
    {
        return $this->capturedRetryCount;
    }

    /** @return list<string> */
    public function capturedFailureMessages(): array
    {
        return $this->capturedFailureMessages;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (null !== $this->captureDeduplicationId && $this->matches($message, $this->captureDeduplicationId)) {
            ++$this->capturedFailureCount;
            $this->capturedFailureMessages[] = implode(' | ', $this->flattenExceptionMessages($event->getThrowable()));
        }

        if (null === $this->armedDeduplicationId || !$this->matches($message, $this->armedDeduplicationId)) {
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
        $message = $event->getEnvelope()->getMessage();

        // Confirms Messenger actually re-sent the envelope, not just that
        // WorkerMessageFailedEvent::willRetry() was true.
        if (null !== $this->captureDeduplicationId && $this->matches($message, $this->captureDeduplicationId)) {
            ++$this->capturedRetryCount;
        }

        if (null !== $this->armedDeduplicationId && $this->matches($message, $this->armedDeduplicationId)) {
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

    /**
     * Flattens a (possibly HandlerFailedException-wrapped) exception chain
     * into every message in it, so callers can grep for a specific DB
     * constraint name without guessing which exception class carries it.
     *
     * @return list<string>
     */
    private function flattenExceptionMessages(\Throwable $throwable): array
    {
        if ($throwable instanceof HandlerFailedException) {
            $messages = [];
            foreach ($throwable->getWrappedExceptions() as $wrapped) {
                $messages = [...$messages, ...$this->flattenExceptionMessages($wrapped)];
            }

            return $messages;
        }

        $messages = [$throwable->getMessage()];
        if (null !== $throwable->getPrevious()) {
            $messages = [...$messages, ...$this->flattenExceptionMessages($throwable->getPrevious())];
        }

        return $messages;
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
