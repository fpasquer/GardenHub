<?php

declare(strict_types=1);

namespace Tests\TelegramAlerts;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test double standing in for the real Telegram Bot API. Records every
 * request (for assertions) and serves canned responses from a queue set up
 * by the test script, so no scenario ever reaches the real network.
 */
final class FakeTelegramHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private static array $requests = [];

    /** @var list<MockResponse> */
    private static array $queue = [];

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift(self::$queue) ?? new MockResponse((string) json_encode(['ok' => true]), ['http_code' => 200]);
        });
    }

    public static function reset(): void
    {
        self::$requests = [];
        self::$queue = [];
    }

    public static function queueResponse(MockResponse $response): void
    {
        self::$queue[] = $response;
    }

    /** @return list<array{method: string, url: string, options: array<string, mixed>}> */
    public static function requests(): array
    {
        return self::$requests;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
