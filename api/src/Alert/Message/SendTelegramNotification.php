<?php

namespace App\Alert\Message;

/**
 * Durable request to deliver one alert notification to Telegram. Dispatched
 * from inside the same explicit transaction that writes the AlertIncident
 * row, so enqueueing is atomic with the incident state change.
 */
final readonly class SendTelegramNotification
{
    public function __construct(
        public string $alertType,
        public string $subjectKey,
        public string $event,
        public \DateTimeImmutable $occurredAt,
        public ?float $value = null,
        public ?string $unit = null,
        public ?string $context = null,
    ) {
    }
}
