<?php

namespace App\Alert;

use App\Alert\Message\RecordProcessingFailureAlert;
use App\Mqtt\ChirpStackUplink;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records a processing_failure alert incident when a ChirpStackUplink
 * message's Messenger retries are exhausted.
 *
 * Priority MUST stay below 100 (SendFailedMessageForRetryListener's
 * priority): that listener is the one that decides and sets willRetry(),
 * so this listener has to run after it to see the final outcome.
 *
 * Deliberately does not touch the ORM/EntityManager here: a failure that
 * reached this listener may have left the EntityManager closed (e.g. after
 * a DBAL exception), so only a durable message dispatch is safe. The actual
 * incident write happens in RecordProcessingFailureAlertHandler, on a fresh
 * worker invocation with its own EntityManager.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: 0)]
final class AlertProcessingFailureListener
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ChirpStackUplink) {
            return;
        }

        $this->messageBus->dispatch(new RecordProcessingFailureAlert(
            $message->deduplicationId,
            $event->getThrowable()->getMessage(),
        ));
    }
}
