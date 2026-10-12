<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringProposal;
use App\Entity\WateringRun;
use App\Tests\Support\RecordingTelegramGateway;
use App\Tests\Support\RecordingWateringPublisher;
use App\Tests\Support\WateringHarness;
use App\Watering\ActuatorState;
use App\Watering\WateringRunStatus;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-watering-orm')]
final class WateringOrmTest extends TestCase
{
    private WateringHarness $harness;
    private int $device;
    private int $run;
    private int $proposal;

    protected function setUp(): void
    {
        $this->harness = new WateringHarness();
        $db = $this->harness->connection;
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('device', ['name' => 'orm-mapping', 'created_at' => $now]);
        $this->device = (int) $db->lastInsertId();
        $db->insert('watering_run', ['requested_seconds' => 3, 'status' => 'requested',
            'requested_at' => $now, 'deadline_at' => $now]);
        $this->run = (int) $db->lastInsertId();
        $this->proposal = $this->insertProposal($this->run);
    }

    protected function tearDown(): void
    {
        $this->harness->shutdown();
    }

    public function testJsonContainsIntegerIdsAndNoNestedEntities(): void
    {
        $serializer = \testSerializer($this->harness->kernel);
        $proposal = \freshProposal($this->harness->manager, $this->proposal);
        $json = json_decode($serializer->serialize($proposal, 'json',
            ['groups' => ['read:watering_proposal']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->proposal, $json['id']);
        self::assertSame($this->device, $json['deviceId']);
        self::assertSame($this->run, $json['runId']);
        self::assertSame('executing', $json['status']);
        self::assertSame('sent', $json['notificationStatus']);
        self::assertArrayNotHasKey('run', $json);
        self::assertArrayNotHasKey('device', $json);
        $run = (new WateringRun())->setStatus(WateringRunStatus::Running->value)
            ->setLastState(ActuatorState::On->value);
        $json = json_decode($serializer->serialize($run, 'json',
            ['groups' => ['read:watering_run']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('running', $json['status']);
        self::assertSame('ON', $json['lastState']);
        $this->harness->connection->executeStatement('UPDATE watering_control SET active_run_id = ? WHERE id = 1', [$this->run]);
        $json = json_decode($serializer->serialize(\freshControl($this->harness->manager),
            'json', ['groups' => ['read:watering_control']]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->run, $json['activeRunId']);
        self::assertArrayNotHasKey('activeRun', $json);
    }

    public function testBulkCompareAndSetRequiresFreshRead(): void
    {
        $manager = $this->harness->manager;
        $proposals = $manager->getRepository(WateringProposal::class);
        $manager->clear();
        $stale = $manager->find(WateringProposal::class, $this->proposal);
        self::assertSame(1, $proposals->finishExecution($this->proposal,
            WateringProposal::STATUS_FAILED, 'boom', null));
        self::assertSame(WateringProposal::STATUS_EXECUTING, $stale->getStatus());
        $fresh = $proposals->fresh($this->proposal);
        self::assertSame(WateringProposal::STATUS_FAILED, $fresh->getStatus());
        self::assertSame('boom', $fresh->getFailure());
        self::assertSame($this->run, $fresh->getRunId());
        self::assertSame(0, $proposals->finishExecution($this->proposal,
            WateringProposal::STATUS_APPROVED, null, $this->run));
    }

    public function testReferrerRemovalKeepsRunAndForeignKeysRejectOrphans(): void
    {
        $db = $this->harness->connection;
        $linked = $this->insertProposal($this->run);
        $db->executeStatement('DELETE FROM watering_proposal WHERE id = ?', [$linked]);
        $db->executeStatement('UPDATE watering_control SET active_run_id = ? WHERE id = 1', [$this->run]);
        $db->executeStatement('UPDATE watering_control SET active_run_id = NULL WHERE id = 1');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run WHERE id = ?', [$this->run]));
        $this->foreignKey(fn () => $this->insertProposal(2147483000));
        $this->foreignKey(fn () => $db->executeStatement('UPDATE watering_control SET active_run_id = 2147483000 WHERE id = 1'));
        $this->insertProposal($this->run);
        $this->foreignKey(fn () => $db->executeStatement('DELETE FROM watering_run WHERE id = ?', [$this->run]));
    }

    public function testInvalidCallbackBoundsAreAcknowledgedWithoutStateChanges(): void
    {
        $publisher = new RecordingWateringPublisher();
        $telegram = new RecordingTelegramGateway();
        $manager = $this->harness->manager;
        $policy = \newTestPolicy($manager, \newTestManager($manager, $publisher), 'test/topic');
        $bot = \newTestBot($manager, $policy, $telegram);
        $before = $this->harness->connection->fetchAllAssociative('SELECT id, status FROM watering_proposal ORDER BY id');
        self::assertSame('ignored', $policy->decide($this->proposal, 'water-now'));
        $payloads = ['w:a:0', 'w:a:-1', 'w:a:2147483648', 'w:a:99999999999',
            'w:a:'.str_repeat('9', 40), 'w:a:018f4c5e-0000-7000-8000-000000000000',
            'w:a:', 'w:x:1', 'garbage'];
        foreach ($payloads as $index => $data) {
            $bot->process(['update_id' => $index, 'callback_query' => [
                'id' => 'c'.$index, 'from' => ['id' => 123],
                'message' => ['chat' => ['id' => 456, 'type' => 'private']], 'data' => $data]]);
        }
        self::assertCount(count($payloads), $telegram->acks);
        self::assertSame($before, $this->harness->connection->fetchAllAssociative('SELECT id, status FROM watering_proposal ORDER BY id'));
        self::assertSame([], $publisher->calls);
    }

    private function insertProposal(?int $run): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->harness->connection->insert('watering_proposal', [
            'device_id' => $this->device, 'status' => 'executing',
            'created_at' => $now, 'expires_at' => $now, 'duration_seconds' => 3,
            'readings_json' => '[]', 'notification_status' => 'sent', 'run_id' => $run]);

        return (int) $this->harness->connection->lastInsertId();
    }

    private function foreignKey(callable $statement): void
    {
        try {
            $statement();
            self::fail('Expected Doctrine foreign key violation.');
        } catch (ForeignKeyConstraintViolationException $exception) {
            self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $exception);
        }
    }
}