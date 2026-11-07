<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringRun;
use App\Tests\Support\AssertsRejection;
use App\Tests\Support\RecordingWateringPublisher;
use App\Tests\Support\WateringHarness;
use App\Watering\WateringManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-watering-control')]
final class WateringControlTest extends TestCase
{
    use AssertsRejection;

    private WateringHarness $harness;
    private RecordingWateringPublisher $publisher;
    private WateringManager $watering;

    protected function setUp(): void
    {
        $this->harness = new WateringHarness();
        $this->harness->connection->executeStatement('UPDATE watering_control SET monitor_seen_at = NULL WHERE id = 1');
        $this->publisher = new RecordingWateringPublisher();
        $this->watering = \newTestManager($this->harness->manager, $this->publisher);
    }

    protected function tearDown(): void
    {
        $this->harness->shutdown();
    }

    #[DataProvider('invalidStates')]
    public function testInvalidActuatorStateIsRejected(string $payload): void
    {
        self::assertFalse($this->watering->observe($payload));
    }

    #[DataProvider('requestGuards')]
    public function testRequestGuards(
        string $guard,
        string $class,
        string $message,
    ): void {
        $manager = $this->watering;
        $duration = 3;
        if ('zero' === $guard || 'too long' === $guard) {
            $duration = 'zero' === $guard ? 0 : 66;
        } elseif ('environment' === $guard || 'disabled' === $guard) {
            $manager = \newTestManager($this->harness->manager, $this->publisher,
                'disabled' !== $guard, 'environment' === $guard ? 'test' : 'dev');
        } elseif ('stale heartbeat' === $guard) {
            $this->harness->connection->executeStatement('UPDATE watering_control SET monitor_seen_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = 1');
        }
        self::assertRejected(
            $class,
            $message,
            fn () => $manager->request($duration),
        );
        self::assertSame([], $this->publisher->calls);
    }

    public function testProductionRequestSurvivesManagerRestartAndEnforcesCooldown(): void
    {
        $this->watering = \newTestManager($this->harness->manager, $this->publisher, true, 'prod');
        $this->watering->heartbeat();
        $id = $this->watering->request(3, static function (\DateTimeImmutable $now): void {
            self::assertCount(1, func_get_args());
        });
        $reserved = $this->storedRun($id);
        self::assertSame(13, $reserved->getDeadlineAt()->getTimestamp() - $reserved->getRequestedAt()->getTimestamp());
        self::assertSame([['watering_times' => 3, 'state' => 'ON']], $this->publisher->calls);
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $this->watering->request(3),
        );
        self::assertFalse($this->watering->observe('{"state":"OFF"}'));
        self::assertSame('pending', $this->storedRun($id)->getStatus());
        self::assertFalse($this->watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}'));
        self::assertSame('running', $this->storedRun($id)->getStatus());
        $restarted = \newTestManager($this->harness->manager, $this->publisher);
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $restarted->request(3),
        );
        $restarted->observe('{"state":"OFF"}');
        self::assertSame('completed', $this->storedRun($id)->getStatus());
        self::assertRejected(
            \DomainException::class,
            'cooldown has not elapsed',
            fn () => $restarted->request(3),
        );
    }

    public function testFailedPublishRetainsReservationAndTimeoutRequiresManualReview(): void
    {
        $this->watering->heartbeat();
        $this->publisher->fail = true;
        self::assertRejected(
            \RuntimeException::class,
            'Command delivery uncertain',
            fn () => $this->watering->request(3),
        );
        $id = $this->activeId();
        self::assertNotNull($id);
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertTrue($this->watering->requiresStop());
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $this->watering->request(3),
        );
        $this->harness->connection->executeStatement("UPDATE watering_run SET status = 'pending', deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?", [$id]);
        $restarted = \newTestManager($this->harness->manager, $this->publisher);
        self::assertTrue($restarted->expire());
        self::assertSame('timed_out', $this->storedRun($id)->getStatus());
        $restarted->observe('{"state":"OFF"}');
        self::assertTrue($restarted->requiresStop());
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $restarted->request(3),
        );
        $restarted->acknowledgeStopped();
        self::assertSame('reviewed', $this->storedRun($id)->getStatus());
        self::assertFalse($restarted->requiresStop());
    }

    #[DataProvider('alarms')]
    public function testAlarmRequestsStopAndBlocksCycle(string $payload): void
    {
        $this->watering->heartbeat();
        $id = $this->watering->request(3);
        self::assertTrue($this->watering->observe($payload));
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
    }

    public function testRollingDailyBudgetRejectsExcess(): void
    {
        $this->watering->heartbeat();
        $this->harness->connection->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (1, 129, 'completed', NOW(), NOW())");
        self::assertRejected(
            \DomainException::class,
            'Rolling 24-hour',
            fn () => $this->watering->request(2),
        );
    }

    #[DataProvider('overdueStates')]
    public function testOverdueOffReportTimesOutAndPreservesReservation(bool $running): void
    {
        $this->watering->heartbeat();
        $id = $this->watering->request(3);
        if ($running) {
            $this->watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}');
            self::assertSame('running', $this->storedRun($id)->getStatus());
        }
        $this->harness->connection->executeStatement('UPDATE watering_run SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$id]);
        self::assertTrue($this->watering->observe('{"state":"OFF"}'));
        self::assertSame('timed_out', $this->storedRun($id)->getStatus());
        self::assertTrue($this->watering->requiresStop());
        self::assertSame($id, $this->activeId());
    }

    public function testLaterReportsCannotChangeAlreadyBlockedRun(): void
    {
        $this->watering->heartbeat();
        $id = $this->watering->request(3);
        self::assertTrue($this->watering->observe('{"state":"ON","alarm_1":true}'));
        $this->harness->connection->executeStatement('UPDATE watering_run SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [$id]);
        $this->watering->observe('{"state":"OFF"}');
        $this->watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}');
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertSame('Water shortage or low battery reported', $this->storedRun($id)->getError());
        self::assertTrue($this->watering->requiresStop());
        self::assertSame($id, $this->activeId());
    }

    public function testRequiresStopUsesActiveIdInsteadOfLatestTimestampTie(): void
    {
        $db = $this->harness->connection;
        $now = gmdate('Y-m-d H:i:s');
        $db->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at, error) VALUES (1, 3, 'uncertain', ?, ?, 'tie-break fixture')", [$now, $now]);
        $db->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (2, 3, 'completed', ?, ?)", [$now, $now]);
        $db->executeStatement('UPDATE watering_control SET active_run_id = 1 WHERE id = 1');
        self::assertSame(2, $this->watering->latest()?->getId());
        self::assertTrue($this->watering->requiresStop());
    }

    private function storedRun(int $id): WateringRun
    {
        $run = \freshRun($this->harness->manager, $id);
        self::assertInstanceOf(WateringRun::class, $run);
        return $run;
    }

    private function activeId(): ?int
    {
        return \freshControl($this->harness->manager)->getActiveRun()?->getId();
    }

    public static function invalidStates(): iterable
    {
        foreach (['invalid-json', '{}', '{"state":"UNKNOWN"}', '{"state":true}', '{"state":1}'] as $payload) {
            yield $payload => [$payload];
        }
    }

    public static function requestGuards(): iterable
    {
        $duration = [\DomainException::class, 'Duration must be between'];
        $enablement = [\LogicException::class, 'requires explicit enablement'];
        $monitor = [\DomainException::class, 'monitor is not reporting'];
        yield 'zero' => ['zero', ...$duration];
        yield 'too long' => ['too long', ...$duration];
        yield 'environment' => ['environment', ...$enablement];
        yield 'disabled' => ['disabled', ...$enablement];
        yield 'missing heartbeat' => ['missing heartbeat', ...$monitor];
        yield 'stale heartbeat' => ['stale heartbeat', ...$monitor];
    }

    public static function alarms(): iterable
    {
        yield 'water shortage ON' => ['{"state":"ON","alarm_1":true}'];
        yield 'low battery OFF' => ['{"state":"OFF","battery_low":true}'];
    }

    public static function overdueStates(): iterable
    {
        yield 'pending' => [false];
        yield 'running' => [true];
    }
}