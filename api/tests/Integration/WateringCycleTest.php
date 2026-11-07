<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringRun;
use App\Tests\Support\AssertsRejection;
use App\Tests\Support\RecordingWateringPublisher;
use App\Tests\Support\WateringHarness;
use App\Watering\WateringManager;
use App\Watering\WateringReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\Process;

#[Group('functional_gardenhub-watering-control')]
final class WateringCycleTest extends TestCase
{
    use AssertsRejection;

    private WateringHarness $harness;
    private RecordingWateringPublisher $publisher;
    private WateringManager $watering;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->harness = new WateringHarness();
        $this->publisher = new RecordingWateringPublisher();
        $this->clock = new MockClock(\App\Watering\UtcClock::now());
        $this->watering = \newTestManager($this->harness->manager, $this->publisher, clock: $this->clock);
        $this->watering->heartbeat();
    }

    protected function tearDown(): void
    {
        $this->harness->shutdown();
    }

    public function testOneCycleHasTwoExactCommandsAndAnOffAnchoredBreak(): void
    {
        $id = $this->request();
        self::assertSame(130, $this->storedRun($id)->getRequestedSeconds());
        self::assertSame(2, $this->storedRun($id)->getStages()->count());
        self::assertSame([['watering_times' => 65, 'state' => 'ON']], $this->publisher->calls);
        $this->watering->observe('{"state":"OFF"}');
        self::assertSame('pending', $this->storedRun($id)->getStatus());
        $this->finishFirst($id);
        $run = $this->storedRun($id);
        self::assertSame(1800, $run->getSecondStageDueAt()->getTimestamp()
            - $run->getCurrentStage()->getFinishedAt()->getTimestamp());
        $due = $run->getSecondStageDueAt();
        $this->watering->observe('{"state":"OFF"}');
        self::assertEquals($due, $this->storedRun($id)->getSecondStageDueAt());
        self::assertFalse($this->watering->expire());
        $this->watering->advanceCycle();
        self::assertCount(1, $this->publisher->calls);
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $this->watering->request(1),
        );
        self::assertRejected(
            \DomainException::class,
            'active or requires review',
            fn () => $this->request(),
        );
        self::assertRejected(
            \RuntimeException::class,
            'is unresolved',
            fn () => (new WateringReadiness($this->harness->connection))
                ->assertIdle(),
        );
        $this->makeDue($id);
        $this->dispatchSecond();
        self::assertSame([['watering_times' => 65, 'state' => 'ON'],
            ['state' => 'OFF'], ['watering_times' => 65, 'state' => 'ON']], $this->publisher->calls);
        $this->watering->observe('{"state":"OFF"}');
        self::assertSame('pending', $this->storedRun($id)->getStatus());
        $this->watering->observe('{"state":"ON"}');
        $this->watering->observe('{"state":"OFF"}');
        self::assertSame('completed', $this->storedRun($id)->getStatus());
        self::assertSame(['completed', 'completed'],
            $this->storedRun($id)->getStages()->map(static fn ($s) => $s->getStatus())->toArray());
        (new WateringReadiness($this->harness->connection))->assertIdle();
    }

    public function testSecondStageDoesNotStartOneSecondEarlyOrTwice(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->clock->sleep(1799);
        $this->watering->heartbeat();
        $this->watering->advanceCycle();
        self::assertCount(1, $this->publisher->calls);
        $this->makeDue($id);
        $this->dispatchSecond();
        $restarted = \newTestManager($this->harness->manager, $this->publisher, clock: $this->clock);
        $restarted->advanceCycle();
        $this->watering->advanceCycle();
        self::assertCount(3, $this->publisher->calls);
        self::assertNotNull($this->storedRun($id)->getStages()->last()->getRequestedAt());
    }

    public function testRealPumpPayloadWithoutBatteryLowStartsStageTwo(): void
    {
        $id = $this->request();
        $this->watering->observe('{"state":"ON"}');
        $this->clock->sleep(65);
        $this->watering->observe($this->realPumpOff());
        self::assertSame('break', $this->storedRun($id)->getStatus());
        $this->makeDue($id);
        $this->watering->advanceCycle();
        $this->watering->observe($this->realPumpOff());
        $this->watering->advanceCycle();
        self::assertSame(
            ['watering_times' => 65, 'state' => 'ON'],
            $this->publisher->calls[2],
        );
        self::assertSame('pending', $this->storedRun($id)->getStatus());
    }

    public function testRestartInvalidatesEvidenceButPreservesDueTimeAndReservation(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->watering->advanceCycle();
        $this->safeOff();
        $this->watering->invalidateHeartbeat();
        $this->watering = \newTestManager($this->harness->manager, $this->publisher, clock: $this->clock);
        $this->watering->advanceCycle();
        self::assertCount(2, $this->publisher->calls);
        $this->watering->heartbeat();
        $this->watering->advanceCycle();
        self::assertSame(['state' => 'OFF'], $this->publisher->calls[2]);
        $this->watering->observe('{"state":"OFF","alarm_1":false,"battery_low":false}', true);
        $this->watering->advanceCycle();
        self::assertCount(3, $this->publisher->calls);
        $this->safeOff();
        $this->watering->advanceCycle();
        self::assertSame(['watering_times' => 65, 'state' => 'ON'], $this->publisher->calls[3]);
    }

    public function testFullBudgetIsReservedBeforePublishing(): void
    {
        $this->harness->connection->executeStatement(
            "INSERT INTO watering_run (requested_seconds, status, requested_at, deadline_at)
             VALUES (1, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        self::assertRejected(
            \DomainException::class,
            'Rolling 24-hour',
            fn () => $this->request(),
        );
        self::assertSame([], $this->publisher->calls);
        self::assertSame(0, (int) $this->harness->connection->fetchOne('SELECT COUNT(*) FROM watering_stage'));
    }

    public function testReservationSurvivesDowntimeBeyondTwentyFourHours(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->harness->connection->executeStatement(
            'UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE id = ?', [$id],
        );
        $this->harness->connection->executeStatement(
            'UPDATE watering_stage SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE run_id = ? AND position = 1', [$id],
        );
        $runs = $this->harness->manager->getRepository(WateringRun::class);
        self::assertSame(65, $runs->sumRequestedSince(new \DateTimeImmutable('-24 hours')));
        $this->dispatchSecond();
        self::assertSame(65, $runs->sumRequestedSince(new \DateTimeImmutable('-24 hours')));
        self::assertSame('pending', $this->storedRun($id)->getStatus());
    }

    public function testChangedBudgetCancelsOnlyUndispatchedStageAfterSafeOff(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->watering = \newTestManager($this->harness->manager, $this->publisher, true, 'dev', 65, 65, clock: $this->clock);
        $this->dispatchSecond();
        self::assertSame('cancelled', $this->storedRun($id)->getStatus());
        self::assertSame('cancelled', $this->storedRun($id)->getStages()->last()->getStatus());
        self::assertCount(2, $this->publisher->calls);
        (new WateringReadiness($this->harness->connection))->assertIdle();
        self::assertSame(65, $this->harness->manager->getRepository(WateringRun::class)
            ->sumRequestedSince(new \DateTimeImmutable('-24 hours')));
    }

    #[DataProvider('unsafeStates')]
    public function testUnsafeOrUnknownTelemetryPreventsSecondStage(string $payload): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->watering->advanceCycle();
        $this->watering->observe($payload);
        $this->watering->advanceCycle();
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertNull($this->storedRun($id)->getStages()->last()->getRequestedAt());
        self::assertTrue($this->watering->requiresStop());
        $this->watering->acknowledgeStopped();
        $this->watering->advanceCycle();
        self::assertCount(2, $this->publisher->calls);
        self::assertSame('cancelled', $this->storedRun($id)->getStages()->last()->getStatus());
    }

    public static function unsafeStates(): array
    {
        return [
            ['{"state":"OFF","alarm_1":true,"battery_low":false}'],
            ['{"state":"OFF","alarm_1":false,"battery_low":true}'],
            ['{"state":"OFF","alarm_1":false,"battery":20}'],
            ['{"state":"OFF"}'],
            ['{"state":"ON","alarm_1":false,"battery_low":false}'],
        ];
    }

    public function testMissingFreshConfirmationBlocksAfterProbeDeadline(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->watering->advanceCycle();
        $this->clock->sleep(10);
        $this->watering->heartbeat();
        $this->watering->advanceCycle();
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertCount(2, $this->publisher->calls);
    }

    public function testPreProbeOffInTheSameSecondCannotStartStageTwo(): void
    {
        $id = $this->dueCycle();
        $this->safeOff();
        $seen = $this->harness->connection->fetchOne(
            'SELECT actuator_seen_at FROM watering_control WHERE id = 1',
        );
        $this->watering->advanceCycle();
        self::assertSame($seen, $this->storedRun($id)->getSafetyProbeAt()->format('Y-m-d H:i:s'));
        $this->watering->advanceCycle();
        self::assertSame('break', $this->storedRun($id)->getStatus());
        self::assertNull($this->storedRun($id)->getStages()->last()->getRequestedAt());
        self::assertCount(2, $this->publisher->calls);
        $this->safeOff();
        $this->watering->advanceCycle();
        self::assertSame('pending', $this->storedRun($id)->getStatus());
        self::assertCount(3, $this->publisher->calls);
    }

    #[DataProvider('lateProbeConfirmations')]
    public function testLateProbeConfirmationNeverStartsStageTwo(int $delay): void
    {
        $id = $this->dueCycle();
        $this->watering->advanceCycle();
        $this->clock->sleep($delay);
        $this->watering->heartbeat();
        $this->safeOff();
        $this->watering->advanceCycle();
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertNull($this->storedRun($id)->getStages()->last()->getRequestedAt());
        self::assertTrue($this->watering->requiresStop());
        self::assertCount(2, $this->publisher->calls);
    }

    public static function lateProbeConfirmations(): iterable
    {
        yield 'at the deadline' => [10];
        yield 'after the deadline' => [11];
    }

    public function testTimelyProbeConfirmationRemainsValidOnTheNextPoll(): void
    {
        $id = $this->dueCycle();
        $this->watering->advanceCycle();
        $this->clock->sleep(9);
        $this->watering->heartbeat();
        $this->safeOff();
        $this->clock->sleep(2);
        $this->watering->heartbeat();
        $this->watering->advanceCycle();
        self::assertSame('pending', $this->storedRun($id)->getStatus());
        self::assertCount(3, $this->publisher->calls);
    }

    public function testSecondStageRefreshesCooldownAfterProlongedDowntime(): void
    {
        $this->watering = \newTestManager(
            $this->harness->manager, $this->publisher,
            cooldownSeconds: 1800, clock: $this->clock,
        );
        $id = $this->request();
        $this->finishFirst($id);
        $this->clock->sleep(25 * 3600);
        $this->watering->heartbeat();
        $this->dispatchSecond();
        $claimedAt = $this->storedRun($id)->getStages()->last()->getRequestedAt();
        self::assertSame($claimedAt->format('Y-m-d H:i:s'),
            $this->harness->connection->fetchOne(
                'SELECT last_request_at FROM watering_control WHERE id = 1',
            ));
        $this->watering->observe('{"state":"ON"}');
        $this->clock->sleep(65);
        $this->safeOff();
        $this->watering->heartbeat();
        self::assertRejected(
            \DomainException::class,
            'cooldown has not elapsed',
            fn () => $this->watering->request(1),
        );
        $this->clock->sleep(1800 - 65 - 1);
        $this->watering->heartbeat();
        self::assertRejected(
            \DomainException::class,
            'cooldown has not elapsed',
            fn () => $this->watering->request(1),
        );
        $this->clock->sleep(1);
        $this->watering->heartbeat();
        $this->watering->request(1);
        self::assertCount(4, $this->publisher->calls);
    }

    public function testUncertainSecondCommandIsNeverRetriedAfterRestart(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->watering->advanceCycle();
        $this->safeOff();
        $this->publisher->fail = true;
        self::assertRejected(
            \RuntimeException::class,
            'Command delivery uncertain',
            fn () => $this->watering->advanceCycle(),
        );
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertSame('uncertain', $this->storedRun($id)->getStages()->last()->getStatus());
        $this->publisher->fail = false;
        \newTestManager($this->harness->manager, $this->publisher, clock: $this->clock)->advanceCycle();
        self::assertCount(3, $this->publisher->calls);
    }

    private function request(): int
    {
        return $this->watering->requestCycle('gardenhub/dev/watering/avocado',
            static function (\DateTimeImmutable $now, WateringRun $run): void {});
    }

    private function dueCycle(): int
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);

        return $id;
    }

    public function testChangedActuatorNeverReceivesOriginalCycleCommands(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $other = \newTestManager($this->harness->manager, $this->publisher,
            clock: $this->clock, topic: 'zigbee2mqtt/other-pump');
        $other->advanceCycle();
        self::assertSame('uncertain', $this->storedRun($id)->getStatus());
        self::assertFalse($other->requiresStop());
        self::assertFalse($other->observe('{"state":"ON"}'));
        self::assertCount(1, $this->publisher->calls);
    }

    public function testSecondStageHasItsOwnDeadlineAndStatusOutput(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $this->dispatchSecond();
        $run = $this->storedRun($id);
        self::assertSame(75, $run->getDeadlineAt()->getTimestamp()
            - $run->getStages()->last()->getRequestedAt()->getTimestamp());
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            new \App\Command\WateringStatusCommand($this->watering),
        );
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('requested_seconds: 130', $tester->getDisplay());
        self::assertStringContainsString('stage_1: completed', $tester->getDisplay());
        self::assertStringContainsString('stage_2: pending', $tester->getDisplay());
        $this->clock->sleep(75);
        self::assertTrue($this->watering->expire());
        self::assertSame('timed_out', $this->storedRun($id)->getStatus());
        self::assertSame('timed_out', $this->storedRun($id)->getStages()->last()->getStatus());
        $this->watering->observe('{"state":"OFF"}');
        self::assertTrue($this->watering->requiresStop());
    }

    public function testIndependentWorkersClaimSecondStageOnce(): void
    {
        $id = $this->request();
        $this->finishFirst($id);
        $this->makeDue($id);
        $now = $this->clock->now();
        $db = $this->harness->connection;
        $db->executeStatement(
            'UPDATE watering_run SET safety_probe_at = ? WHERE id = ?',
            [$now->format('Y-m-d H:i:s'), $id],
        );
        $db->executeStatement(
            'UPDATE watering_control SET actuator_seen_at = ?, actuator_safe = 1 WHERE id = 1',
            [$now->format('Y-m-d H:i:s')],
        );
        $path = tempnam(sys_get_temp_dir(), 'watering-claims-');
        self::assertNotFalse($path);
        $children = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $child = new Process([PHP_BINARY,
                    \App\Tests\Support\Command::root().'/tests/watering-control/proposal-approve-child.php',
                    '--advance', $path, $now->format(\DateTimeInterface::ATOM)],
                    '/app', timeout: 30);
                $child->start();
                $children[] = $child;
            }
            foreach ($children as $child) {
                $child->wait();
                self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            }
            self::assertSame("{\"watering_times\":65,\"state\":\"ON\"}\n",
                file_get_contents($path),
                $this->storedRun($id)->getError() ?? 'Unexpected second-stage commands.');
            self::assertSame('pending', $this->storedRun($id)->getStatus());
        } finally {
            foreach ($children as $child) {
                $child->stop();
            }
            unlink($path);
        }
    }

    private function finishFirst(int $id): void
    {
        $this->watering->observe('{"state":"ON"}');
        $this->clock->sleep(65);
        $this->safeOff();
        self::assertSame('break', $this->storedRun($id)->getStatus());
    }

    private function makeDue(int $id): void
    {
        $this->clock->sleep(1800);
        $this->watering->heartbeat();
    }

    private function safeOff(): void
    {
        $this->watering->observe('{"state":"OFF","alarm_1":false,"battery_low":false}');
    }

    /** The OFF report zigbee2mqtt publishes for the real pump. */
    private function realPumpOff(): string
    {
        return json_encode([
            'alarm_1' => false,
            'battery' => 100,
            'interval_day' => 0,
            'linkquality' => 141,
            'state' => 'OFF',
            'update' => ['installed_version' => 45, 'state' => 'idle'],
            'voltage' => 6000,
            'watering_times' => 65,
        ], JSON_THROW_ON_ERROR);
    }

    private function dispatchSecond(): void
    {
        $this->watering->advanceCycle();
        $this->safeOff();
        $this->watering->advanceCycle();
    }

    private function storedRun(int $id): WateringRun
    {
        return \freshRun($this->harness->manager, $id)
            ?? throw new \LogicException('Missing cycle fixture.');
    }
}
