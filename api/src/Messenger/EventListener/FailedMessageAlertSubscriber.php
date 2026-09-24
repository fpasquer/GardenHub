<?php

namespace App\Messenger\EventListener;

use App\Mqtt\ChirpStackUplink;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Sends one Telegram alert per message that terminally fails on the async
 * transport (retries exhausted, landed in the "failed" transport).
 *
 * Must run after SendFailedMessageToFailureTransportListener (priority -100)
 * so the message has already been sent to the "failed" transport by the
 * time this fires; the "async"-only guard skips retries and reprocessing of
 * the "failed" transport itself.
 */
#[WithMonologChannel('telegram')]
final class FailedMessageAlertSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onMessageFailed', -200],
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry() || 'async' !== $event->getReceiverName()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $lines = [
            'MQTT/Messenger alert: message permanently failed.',
            sprintf('Class: %s', $envelope->getMessage()::class),
            sprintf('Retry count: %d', RedeliveryStamp::getRetryCountFromEnvelope($envelope)),
            sprintf('Error type: %s', $this->resolveErrorType($event->getThrowable())),
        ];

        $uplinkId = $this->resolveUplinkId($envelope);
        if (null !== $uplinkId) {
            $lines[] = sprintf('Uplink UUID: %s', $uplinkId);
        }

        $this->logger->critical(implode("\n", $lines));
    }

    private function resolveErrorType(\Throwable $throwable): string
    {
        if ($throwable instanceof HandlerFailedException) {
            $wrapped = array_values($throwable->getWrappedExceptions());
            if ([] !== $wrapped) {
                return $wrapped[0]::class;
            }
        }

        return $throwable::class;
    }

    private function resolveUplinkId(Envelope $envelope): ?string
    {
        $message = $envelope->getMessage();

        return $message instanceof ChirpStackUplink ? $message->deduplicationId : null;
    }
}
