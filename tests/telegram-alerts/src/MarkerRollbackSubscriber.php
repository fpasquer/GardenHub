<?php

declare(strict_types=1);

namespace Tests\TelegramAlerts;

use App\Mqtt\ChirpStackUplink;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Test-only Messenger event hooks recording, for one armed deduplicationId,
 * every failed processing attempt's flattened exception chain plus whether
 * Messenger actually scheduled a retry or handled the message successfully -
 * so the marker-rollback scenario can assert the *exact* injected cause
 * rather than accepting any failure as sufficient.
 */
final class MarkerRollbackSubscriber implements EventSubscriberInterface
{
    private ?string $deduplicationId = null;
    private int $failureCount = 0;
    private int $retryCount = 0;
    private int $handledCount = 0;
    /** @var list<string> */
    private array $failureMessages = [];

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerMessageRetriedEvent::class => 'onMessageRetried',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
        ];
    }

    public function arm(string $deduplicationId): void
    {
        $this->deduplicationId = $deduplicationId;
        $this->failureCount = 0;
        $this->retryCount = 0;
        $this->handledCount = 0;
        $this->failureMessages = [];
    }

    public function disarm(): void
    {
        $this->deduplicationId = null;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    public function handledCount(): int
    {
        return $this->handledCount;
    }

    /** @return list<string> */
    public function failureMessages(): array
    {
        return $this->failureMessages;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->matches($event->getEnvelope()->getMessage())) {
            return;
        }

        ++$this->failureCount;
        $this->failureMessages[] = implode(' | ', $this->flattenExceptionMessages($event->getThrowable()));
    }

    public function onMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        if ($this->matches($event->getEnvelope()->getMessage())) {
            ++$this->retryCount;
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if ($this->matches($event->getEnvelope()->getMessage())) {
            ++$this->handledCount;
        }
    }

    private function matches(object $message): bool
    {
        return null !== $this->deduplicationId
            && $message instanceof ChirpStackUplink
            && $message->deduplicationId === $this->deduplicationId;
    }

    /** @return list<string> */
    private function flattenExceptionMessages(\Throwable $throwable): array
    {
        if ($throwable instanceof HandlerFailedException) {
            $messages = [];
            foreach ($throwable->getWrappedExceptions() as $wrapped) {
                $messages = [...$messages, ...$this->flattenExceptionMessages($wrapped)];
            }

            return $messages;
        }

        return [$throwable->getMessage()];
    }
}
