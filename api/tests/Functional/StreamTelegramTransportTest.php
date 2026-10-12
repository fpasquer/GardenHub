<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Monolog\Telegram\StreamTelegramTransport;
use App\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('functional_gardenhub-telegram-http')]
final class StreamTelegramTransportTest extends TestCase
{
    private LocalHttpServer $server;

    protected function setUp(): void
    {
        $this->server = new LocalHttpServer(
            dirname(__DIR__).'/Support/telegram_fake_server.php',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
    }

    public function testSuccessfulDelivery(): void
    {
        $baseline = $this->currentHandler();
        $transport = new StreamTelegramTransport($this->server->url());
        $transport->send('test-token', 'ok', 'hello');
        self::assertSame($baseline, $this->currentHandler());
    }

    #[DataProvider('failedResponses')]
    public function testFailedResponseThrowsWithoutLeakingToken(
        string $chatId,
    ): void {
        $transport = new StreamTelegramTransport($this->server->url());
        try {
            $transport->send('test-token', $chatId, 'hello');
            self::fail('A failed response must throw RuntimeException.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('test-token', $error->getMessage());
        }
    }

    public static function failedResponses(): array
    {
        return [
            'HTTP error status' => ['http_error'],
            'invalid JSON body' => ['invalid_json'],
            'API reports ok false' => ['not_ok'],
        ];
    }

    public function testConnectionFailureSuppressesWarningAndToken(): void
    {
        $transport = new StreamTelegramTransport(sprintf(
            'http://127.0.0.1:%d', LocalHttpServer::freePort(),
        ));
        $spyCalls = 0;
        set_error_handler(static function () use (&$spyCalls): bool {
            ++$spyCalls;

            return true;
        });
        try {
            try {
                $transport->send('secret-token', 'chat', 'hello');
                self::fail('A refused connection must throw RuntimeException.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString(
                    'secret-token', $error->getMessage(),
                );
            }
            self::assertSame(0, $spyCalls);
        } finally {
            restore_error_handler();
        }
    }

    public function testRepeatedCallsRestoreErrorHandlerStack(): void
    {
        $baseline = $this->currentHandler();
        $marker = static fn (): bool => true;
        set_error_handler($marker);
        try {
            $transport = new StreamTelegramTransport($this->server->url());
            foreach (['ok', 'http_error', 'not_ok'] as $chatId) {
                try {
                    $transport->send('test-token', $chatId, 'hello');
                } catch (RuntimeException) {
                }
            }
        } finally {
            restore_error_handler();
        }
        self::assertSame($baseline, $this->currentHandler());
    }

    private function currentHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => true);
        restore_error_handler();

        return $handler;
    }
}