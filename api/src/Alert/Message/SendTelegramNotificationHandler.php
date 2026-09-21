<?php

namespace App\Alert\Message;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Renders one alert notification and logs it to the telegram_alerts
 * channel, whose only handler (TelegramAlertHandler) performs the actual
 * Telegram delivery. Kept separate from AlertLifecycleService so message
 * formatting has nothing to do with incident bookkeeping.
 */
#[AsMessageHandler]
final class SendTelegramNotificationHandler
{
    public function __construct(
        private readonly LoggerInterface $telegramAlertsLogger,
    ) {
    }

    public function __invoke(SendTelegramNotification $message): void
    {
        $this->telegramAlertsLogger->warning($this->formatMessage($message), [
            'alertType' => $message->alertType,
            'subjectKey' => $message->subjectKey,
            'event' => $message->event,
        ]);
    }

    private function formatMessage(SendTelegramNotification $message): string
    {
        $title = match ($message->event) {
            'resolved' => sprintf('RESOLVED: %s', $this->humanizeAlertType($message->alertType)),
            'reminder' => sprintf('STILL ACTIVE: %s', $this->humanizeAlertType($message->alertType)),
            default => sprintf('ALERT: %s', $this->humanizeAlertType($message->alertType)),
        };

        $lines = [$title, sprintf('Subject: %s', $message->subjectKey)];
        if (null !== $message->value) {
            $lines[] = sprintf('Value: %s%s', $message->value, $message->unit ?? '');
        }
        if (null !== $message->context) {
            $lines[] = sprintf('Details: %s', $message->context);
        }
        $lines[] = sprintf('At: %s', $message->occurredAt->format(\DateTimeInterface::ATOM));

        return implode("\n", $lines);
    }

    private function humanizeAlertType(string $alertType): string
    {
        return str_replace('_', ' ', $alertType);
    }
}
