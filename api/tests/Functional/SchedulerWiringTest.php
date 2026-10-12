<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\SchedulerKernel;
use App\Tests\Support\ProcessEnvironment;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[Group('functional_gardenhub-scheduler-wiring')]
final class SchedulerWiringTest extends TestCase
{
    private SchedulerKernel $kernel;
    private TemporaryWorkspace $workspace;
    private string $timezone;
    private ProcessEnvironment $environment;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        $values = [
            'DATABASE_URL' => 'mysql://root@127.0.0.1:1/scheduler_cron?serverVersion=8.4',
            'TELEGRAM_ENABLED' => 'true',
            'TELEGRAM_BOT_TOKEN' => 'test-token',
            'TELEGRAM_CHAT_ID' => 'test-chat',
            'TELEGRAM_MIN_LEVEL' => 'warning',
        ];
        $this->environment = new ProcessEnvironment(array_keys($values));
        foreach ($values as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new SchedulerKernel($this->workspace->path);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        Clock::set(new Clock());
        date_default_timezone_set($this->timezone);
        $this->environment->restore();
        unset($this->workspace);
    }

    public function testExactlyOneTaskHasTheExpectedCronAndCommand(): void
    {
        $messages = $this->provider()->getSchedule()->getRecurringMessages();
        self::assertCount(1, $messages);
        $message = $messages[array_key_first($messages)];
        self::assertSame('0 22 * * *', (string) $message->getTrigger());
        self::assertStringContainsString(
            'gardenhub:telegram:daily-summary --hours=24',
            (string) $message->getProvider(),
        );
    }

    #[DataProvider('dstDates')]
    public function testNextRunStaysAt22UtcAcrossDst(
        string $instant,
        string $expected,
    ): void {
        date_default_timezone_set('Europe/Paris');
        $messages = $this->provider()->getSchedule()->getRecurringMessages();
        $trigger = $messages[array_key_first($messages)]->getTrigger();
        $next = $trigger->getNextRunDate($this->utc($instant));
        self::assertSame($expected, $next->setTimezone(
            new \DateTimeZone('UTC'),
        )->format('Y-m-d H:i:s'));
    }

    #[DataProvider('startupTimes')]
    public function testFreshWorkerDoesNotExecuteImmediately(string $time): void
    {
        Clock::set(new MockClock($this->utc($time)));
        $generator = new MessageGenerator($this->provider(), 'default', new Clock());
        self::assertSame([], iterator_to_array($generator->getMessages(), false));
    }

    public static function dstDates(): iterable
    {
        yield 'mid-winter' => ['2026-01-15 08:00:00', '2026-01-15 22:00:00'];
        yield 'before spring-forward' => ['2026-03-28 10:00:00', '2026-03-28 22:00:00'];
        yield 'spring-forward' => ['2026-03-29 23:30:00', '2026-03-30 22:00:00'];
        yield 'mid-summer' => ['2026-07-01 12:00:00', '2026-07-01 22:00:00'];
        yield 'before fall-back' => ['2026-10-24 10:00:00', '2026-10-24 22:00:00'];
        yield 'fall-back' => ['2026-10-25 23:30:00', '2026-10-26 22:00:00'];
    }

    public static function startupTimes(): iterable
    {
        yield 'before due' => ['2026-09-24 21:59:59'];
        yield 'exactly due' => ['2026-09-24 22:00:00'];
        yield 'after due' => ['2026-09-24 22:00:01'];
    }

    private function provider(): ScheduleProviderInterface
    {
        return $this->kernel->getContainer()->get('test.scheduler_provider_default');
    }

    private function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}