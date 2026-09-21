<?php

namespace App\Alert\Message;

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Opens (or re-confirms) the processing_failure incident for one uplink.
 * Runs on a fresh Messenger worker invocation with its own EntityManager,
 * so unlike the listener that dispatched this message, it is safe to use
 * AlertLifecycleService (and therefore the ORM) here.
 */
#[AsMessageHandler]
final class RecordProcessingFailureAlertHandler
{
    public function __construct(
        private readonly AlertLifecycleService $lifecycle,
        private readonly int $confirmationCount,
        private readonly int $recoveryCount,
    ) {
    }

    public function __invoke(RecordProcessingFailureAlert $message): void
    {
        $this->lifecycle->openOrAdvance(
            'processing_failure',
            sprintf('chirpstack_uplink:%s', $message->deduplicationId),
            new AlertSignal(breach: true, measuredAt: null, context: $message->failureReason),
            $this->confirmationCount,
            $this->recoveryCount,
        );
    }
}
