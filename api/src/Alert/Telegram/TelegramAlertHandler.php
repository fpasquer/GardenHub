<?php

namespace App\Alert\Telegram;

use App\Alert\Telegram\Exception\TelegramDeliveryException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers log records on the telegram_alerts channel to a Telegram chat via
 * the Bot API. No-ops entirely when disabled (zero network calls, safe with
 * no credentials configured).
 *
 * Retry is intentionally NOT handled here: this handler is only ever invoked
 * from within SendTelegramNotificationHandler (a Messenger handler on the
 * telegram_notifications transport), so throwing lets that transport's own
 * retry_strategy govern retries. Permanent failures (bad token/chat, 4xx)
 * throw UnrecoverableMessageHandlingException to skip straight to the
 * failure transport instead of exhausting retries pointlessly.
 */
final class TelegramAlertHandler extends AbstractProcessingHandler
{
    private const int MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly TelegramConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $fallbackLogger,
        int|string|Level $level = Level::Warning,
    ) {
        parent::__construct($level, bubble: false);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->config->enabled) {
            return;
        }

        try {
            $this->send($this->formatText($record));
        } catch (TelegramDeliveryException|UnrecoverableMessageHandlingException $e) {
            throw $e;
        } catch (HttpClientExceptionInterface $e) {
            $this->fallbackLogger->error('Telegram alert delivery failed (transport error).', ['exception' => $e->getMessage()]);
            throw new TelegramDeliveryException('Telegram HTTP transport error: '.$e->getMessage());
        }
    }

    private function formatText(LogRecord $record): string
    {
        $text = sprintf('[GardenHub][%s] %s', strtoupper($this->config->environmentLabel), $record->message);

        return mb_strlen($text) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - 1).'…'
            : $text;
    }

    private function send(string $text): void
    {
        $response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendMessage', $this->config->botToken), [
            'timeout' => $this->config->httpTimeout,
            'max_duration' => $this->config->httpMaxDuration,
            'body' => [
                'chat_id' => $this->config->chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ],
        ]);

        $this->assertDeliverySucceeded($response->getStatusCode(), $response->toArray(false));
    }

    /** @param array<string, mixed> $payload */
    private function assertDeliverySucceeded(int $status, array $payload): void
    {
        if (429 !== $status && $status >= 400 && $status < 500) {
            $this->fallbackLogger->error('Telegram alert rejected permanently by Telegram API.', ['status' => $status]);
            throw new UnrecoverableMessageHandlingException(sprintf('Telegram API rejected the request (HTTP %d).', $status));
        }

        if (429 === $status || $status >= 500) {
            throw new TelegramDeliveryException(sprintf('Telegram API returned a retryable HTTP status (%d).', $status));
        }

        if (true !== ($payload['ok'] ?? false)) {
            throw new TelegramDeliveryException('Telegram API response did not report ok:true.');
        }
    }
}
