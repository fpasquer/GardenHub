<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use App\Monolog\Handler\TelegramHandler;
use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Support\ProcessEnvironment;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

#[Group('functional_gardenhub-telegram-logging')]
final class TelegramLoggingTest extends TestCase
{
    public function testEnabledTelegramChannelIsIsolated(): void
    {
        $names = [
            'TELEGRAM_ENABLED', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID',
            'TELEGRAM_MIN_LEVEL', 'APP_ENV',
        ];
        $environment = new ProcessEnvironment($names);
        $kernel = new Kernel('test', true);
        try {
            $this->loadDummyEnvironment($names);
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $appLogger = $container->get('logger');
            $telegramLogger = $container->get('monolog.logger.telegram');
            $transport = $container->get(FakeTelegramTransport::class);

            self::assertFalse($this->hasTelegramHandler($appLogger));
            self::assertTrue($this->hasTelegramHandler($telegramLogger));
            $appLogger->warning('app channel message');
            self::assertSame([], $transport->calls);
            $telegramLogger->warning('telegram channel message');
            self::assertCount(1, $transport->calls);
        } finally {
            $kernel->shutdown();
            $environment->restore();
        }
    }

    private function loadDummyEnvironment(array $names): void
    {
        foreach ($names as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $_SERVER['APP_ENV'] = 'test';
        putenv('APP_ENV=test');
        (new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
    }

    private function hasTelegramHandler(Logger $logger): bool
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TelegramHandler) {
                return true;
            }
        }

        return false;
    }
}