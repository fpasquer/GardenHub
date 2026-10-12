<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ProcessEnvironment;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\SchedulerKernel;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

#[Group('functional_gardenhub-scheduler-delivery')]
final class SchedulerDeliveryTest extends TestCase
{
    private SchedulerKernel $kernel;
    private TemporaryWorkspace $workspace;
    private ProcessEnvironment $environment;

    protected function setUp(): void
    {
        $this->environment = new ProcessEnvironment(['TELEGRAM_ENABLED']);
        $enabled = 'testDisabledTelegramNeverSends' !== $this->name();
        $value = $enabled ? 'true' : 'false';
        putenv(sprintf('TELEGRAM_ENABLED=%s', $value));
        $_ENV['TELEGRAM_ENABLED'] = $_SERVER['TELEGRAM_ENABLED'] = $value;
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SchedulerKernel($this->workspace->path);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($manager, 'scheduler_cron');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        $this->environment->restore();
        Clock::set(new Clock());
        unset($this->workspace);
    }

    public function testRealDueMessageReachesTelegram(): void
    {
        $generator = $this->generator();
        self::assertSame([], $this->poll($generator, '2026-09-21 00:00:00'));
        self::assertSame([], $this->poll($generator, '2026-09-21 21:59:59'));
        $due = $this->poll($generator, '2026-09-21 22:00:01');
        self::assertCount(1, $due);
        self::assertInstanceOf(RunCommandMessage::class, $due[0]);
        self::assertStringContainsString(
            'gardenhub:telegram:daily-summary --hours=24', $due[0]->input,
        );
        $transport = $this->kernel->getContainer()->get('test.fake_telegram_transport');
        $transport->calls = [];
        $this->dispatch($due[0]);
        self::assertCount(1, $transport->calls);
    }

    public function testDeliveryFailurePropagates(): void
    {
        $transport = $this->kernel->getContainer()->get('test.fake_telegram_transport');
        $transport->armToThrow();
        $generator = $this->generator();
        $this->poll($generator, '2026-09-22 00:00:00');
        $due = $this->poll($generator, '2026-09-22 22:00:01');
        self::assertCount(1, $due);
        try {
            $this->dispatch($due[0]);
            self::fail('The Telegram delivery failure must propagate.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(RunCommandFailedException::class,
                array_values($exception->getWrappedExceptions())[0] ?? null);
        }
    }

    public function testDisabledTelegramNeverSends(): void
    {
        $generator = $this->generator();
        $this->poll($generator, '2026-09-23 00:00:00');
        $due = $this->poll($generator, '2026-09-23 22:00:01');
        self::assertCount(1, $due);
        $this->dispatch($due[0]);
        self::assertSame([], $this->kernel->getContainer()
            ->get('test.fake_telegram_transport')->calls);
    }

    private function generator(): MessageGenerator
    {
        return new MessageGenerator($this->kernel->getContainer()
            ->get('test.scheduler_provider_default'), 'default', new Clock());
    }

    private function poll(MessageGenerator $generator, string $time): array
    {
        Clock::set(new MockClock(new \DateTimeImmutable(
            $time, new \DateTimeZone('UTC'),
        )));

        return iterator_to_array($generator->getMessages(), false);
    }

    private function dispatch(RunCommandMessage $message): void
    {
        $this->kernel->getContainer()->get('test.messenger.default_bus')
            ->dispatch($message);
    }
}