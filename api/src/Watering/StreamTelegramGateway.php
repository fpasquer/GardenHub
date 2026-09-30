<?php

declare(strict_types=1);

namespace App\Watering;

/** Separate from the Monolog alert transport; outbound Bot API calls only. */
final class StreamTelegramGateway implements TelegramGateway
{
    public function __construct(private readonly string $botToken, private readonly string $chatId)
    {
    }

    public function send(string $text, int $proposalId): int
    {
        $reply = $this->call('sendMessage', [
            'chat_id' => $this->chatId, 'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => 'Approve', 'callback_data' => sprintf('w:a:%d', $proposalId)],
                ['text' => 'Reject', 'callback_data' => sprintf('w:r:%d', $proposalId)],
            ]]], JSON_THROW_ON_ERROR),
        ]);
        $id = $reply['message_id'] ?? null;
        if (!is_int($id)) {
            throw new \RuntimeException('Telegram returned no message ID.');
        }
        return $id;
    }

    public function updates(int $offset): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset, 'timeout' => 20, 'allowed_updates' => json_encode(['callback_query'], JSON_THROW_ON_ERROR),
        ], 30);
        if (!array_is_list($result)) {
            throw new \RuntimeException('Telegram returned invalid updates.');
        }
        return $result;
    }

    public function acknowledge(string $callbackId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function edit(int $messageId, string $text): void
    {
        $this->call('editMessageText', ['chat_id' => $this->chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR)]);
    }

    private function call(string $method, array $params, int $timeout = 8): mixed
    {
        if ($this->botToken === '' || $this->chatId === '') {
            throw new \LogicException('Interactive Telegram bot is not configured.');
        }
        $url = 'https://api.telegram.org/bot'.$this->botToken.'/'.$method;
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($params), 'timeout' => $timeout, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        set_error_handler(static fn (): bool => true);
        try {
            $body = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }
        if ($body === false || !preg_match('/^HTTP\/\S+ 2\d\d/', $headers[0] ?? '')) {
            throw new \RuntimeException('Telegram API request failed.');
        }
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new \RuntimeException('Telegram API rejected request.');
        }
        return $data['result'] ?? null;
    }
}
