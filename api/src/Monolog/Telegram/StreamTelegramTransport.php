<?php

namespace App\Monolog\Telegram;

/**
 * Sends Telegram messages over PHP streams (no curl extension required).
 */
class StreamTelegramTransport implements InterfaceTelegramTransport
{
    private const API_BASE_URL = 'https://api.telegram.org';

    // Bounds the connection and each individual read; not a total-duration
    // guarantee for the whole request (see README).
    private const TIMEOUT_SECONDS = 5.0;

    public function send(string $botToken, string $chatId, string $text): void
    {
        $url = sprintf('%s/bot%s/sendMessage', self::API_BASE_URL, $botToken);
        $body = http_build_query(['chat_id' => $chatId, 'text' => $text]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                // No redirects: a redirect could exfiltrate the token-bearing URL to another host.
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        [$response, $responseHeaders] = $this->fetch($url, $context);

        if (false === $response) {
            throw new \RuntimeException('Telegram request failed (connection error, timeout, or DNS failure).');
        }

        $this->assertSuccessfulResponse($response, $responseHeaders);
    }

    /**
     * file_get_contents() emits an E_WARNING containing the full request URL
     * (which embeds the bot token) on failure. Swallow it at the language
     * level rather than relying on the global error handler configuration.
     *
     * @return array{0: string|false, 1: string[]}
     */
    private function fetch(string $url, $context): array
    {
        $previousHandler = set_error_handler(static fn (): bool => true);

        try {
            $response = @file_get_contents($url, false, $context);

            // $http_response_header is populated in this scope by the call above.
            return [$response, $http_response_header ?? []];
        } finally {
            set_error_handler($previousHandler);
        }
    }

    /**
     * @param string[] $responseHeaders
     */
    private function assertSuccessfulResponse(string $response, array $responseHeaders): void
    {
        $statusLine = $responseHeaders[0] ?? '';
        if (!preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $matches)) {
            throw new \RuntimeException('Telegram API returned a non-success HTTP status.');
        }
        $status = (int) $matches[1];
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Telegram API returned a non-success HTTP status.');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API reported an unsuccessful delivery.');
        }
    }
}
