<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringProposal;
use App\Tests\Support\ProposalFixture;
use App\Tests\Support\RecordingTelegramGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-watering-actuator-switch')]
final class WateringActuatorSwitchTest extends TestCase
{
    private ProposalFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ProposalFixture();
        $this->fixture->reset();
    }

    protected function tearDown(): void
    {
        $this->fixture->harness->shutdown();
    }

    public function testWaitingSimulatorProposalIsInvalidatedBeforeHardwareNotification(): void
    {
        $fixture = $this->fixture;
        $telegram = new RecordingTelegramGateway();
        [$sim] = $fixture->stack(ProposalFixture::SIM_TOPIC, $telegram);
        [$hardware, $bot] = $fixture->stack(ProposalFixture::HW_TOPIC, $telegram);
        $old = $sim->evaluate();
        self::assertInstanceOf(WateringProposal::class, $old);
        self::assertSame(ProposalFixture::SIM_TOPIC, $old->getActuatorTopic());
        $bot->evaluateAndNotify();
        self::assertSame('invalidated', $fixture->column('status', $old->getId()));
        self::assertSame('Actuator changed', $fixture->column('failure', $old->getId()));
        self::assertSame('new', $fixture->column('notification_status', $old->getId()));
        self::assertNull($fixture->column('message_id', $old->getId()));
        self::assertCount(1, $telegram->sent);
        $hardwareId = $telegram->sent[0][1];
        self::assertNotSame($old->getId(), $hardwareId);
        self::assertSame(ProposalFixture::HW_TOPIC, $fixture->column('actuator_topic', $hardwareId));
        self::assertStringContainsString('Actuator: physical pump ('.ProposalFixture::HW_TOPIC.')', $telegram->sent[0][0]);
        self::assertStringNotContainsString(ProposalFixture::SIM_TOPIC, $telegram->sent[0][0]);
        self::assertSame('ignored', $hardware->decide($old->getId(), 'approve'));
        self::assertSame([], $fixture->publisher->calls);
        self::assertSame(0, $fixture->runCount());
        $bot->process(ProposalFixture::callback(100, $hardwareId));
        self::assertSame('approved', $fixture->column('status', $hardwareId));
        self::assertCount(1, $fixture->publisher->calls);
        self::assertSame(1, $fixture->runCount());
    }

    public function testDeliveredSimulatorButtonCannotPublishAndKeepsOriginalActuatorText(): void
    {
        $fixture = $this->fixture;
        $telegram = new RecordingTelegramGateway();
        [, $simBot] = $fixture->stack(ProposalFixture::SIM_TOPIC, $telegram);
        [, $hardwareBot] = $fixture->stack(ProposalFixture::HW_TOPIC, $telegram);
        $simBot->evaluateAndNotify();
        $oldId = $telegram->sent[0][1];
        self::assertSame('sent', $fixture->column('notification_status', $oldId));
        self::assertSame(1, (int) $fixture->column('message_id', $oldId));
        $hardwareBot->process(ProposalFixture::callback(101, $oldId));
        self::assertSame('invalidated', $fixture->column('status', $oldId));
        self::assertSame('Actuator changed', $fixture->column('failure', $oldId));
        self::assertSame([], $fixture->publisher->calls);
        self::assertSame(0, $fixture->runCount());
        self::assertSame(['cb101'], $telegram->acks);
        $hardwareBot->reconcileMessages();
        self::assertCount(1, $telegram->edits);
        self::assertSame(1, $telegram->edits[0][0]);
        self::assertStringContainsString('Actuator: configured MQTT actuator ('.ProposalFixture::SIM_TOPIC.')', $telegram->edits[0][1]);
        self::assertStringNotContainsString('physical pump', $telegram->edits[0][1]);
        self::assertStringContainsString('Decision: invalidated', $telegram->edits[0][1]);
        self::assertSame('final', $fixture->column('notification_status', $oldId));
        $hardwareBot->evaluateAndNotify();
        self::assertCount(2, $telegram->sent);
        $newId = $telegram->sent[1][1];
        self::assertSame(ProposalFixture::HW_TOPIC, $fixture->column('actuator_topic', $newId));
        self::assertStringContainsString('Actuator: physical pump ('.ProposalFixture::HW_TOPIC.')', $telegram->sent[1][0]);
        $hardwareBot->process(ProposalFixture::callback(102, $newId));
        self::assertSame('approved', $fixture->column('status', $newId));
        self::assertCount(1, $fixture->publisher->calls);
        self::assertSame(1, $fixture->runCount());
        self::assertSame([ProposalFixture::SIM_TOPIC => 1, ProposalFixture::HW_TOPIC => 1],
            $fixture->harness->connection->fetchAllKeyValue('SELECT actuator_topic, prompt_count FROM watering_proposal_state WHERE device_id = ?', [$fixture->device]));
    }

    public function testEachActuatorHasIndependentRequestsAfterRejection(): void
    {
        $fixture = $this->fixture;
        [$sim] = $fixture->stack(ProposalFixture::SIM_TOPIC, new RecordingTelegramGateway());
        [$hardware] = $fixture->stack(ProposalFixture::HW_TOPIC, new RecordingTelegramGateway());
        self::assertInstanceOf(WateringProposal::class, $sim->evaluate());
        $first = $hardware->evaluate();
        self::assertInstanceOf(WateringProposal::class, $first);
        self::assertSame('rejected', $hardware->decide($first->getId(), 'reject'));
        self::assertNull($hardware->evaluate());
        $this->ageHardwarePrompt();
        $reminder = $hardware->evaluate();
        self::assertInstanceOf(WateringProposal::class, $reminder);
        self::assertSame('rejected', $hardware->decide($reminder->getId(), 'reject'));
        $this->ageHardwarePrompt();
        self::assertInstanceOf(WateringProposal::class, $hardware->evaluate());
        self::assertSame(1, (int) $fixture->harness->connection->fetchOne('SELECT prompt_count FROM watering_proposal_state WHERE actuator_topic = ?', [ProposalFixture::SIM_TOPIC]));
    }

    public function testLegacyProposalWithoutTopicIsNeverApprovedNotifiedOrRelabeled(): void
    {
        $fixture = $this->fixture;
        $telegram = new RecordingTelegramGateway();
        [$hardware, $bot] = $fixture->stack(ProposalFixture::HW_TOPIC, $telegram);
        $sent = $this->legacyRow('sent', 77);
        $unsent = $this->legacyRow('new', null);
        self::assertSame('invalidated', $hardware->decide($sent, 'approve'));
        self::assertSame([], $fixture->publisher->calls);
        self::assertSame(0, $fixture->runCount());
        $bot->evaluateAndNotify();
        self::assertSame('invalidated', $fixture->column('status', $unsent));
        self::assertNull($fixture->column('message_id', $unsent));
        self::assertCount(1, $telegram->sent);
        self::assertSame(ProposalFixture::HW_TOPIC, $fixture->column('actuator_topic', $telegram->sent[0][1]));
        $bot->reconcileMessages();
        self::assertCount(1, $telegram->edits);
        self::assertSame(77, $telegram->edits[0][0]);
        self::assertStringContainsString('Actuator: not recorded (legacy proposal)', $telegram->edits[0][1]);
        self::assertStringNotContainsString(ProposalFixture::HW_TOPIC, $telegram->edits[0][1]);
        self::assertStringNotContainsString(ProposalFixture::SIM_TOPIC, $telegram->edits[0][1]);
    }

    private function ageHardwarePrompt(): void
    {
        $this->fixture->harness->connection->executeStatement('UPDATE watering_proposal SET decided_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE actuator_topic = ?', [ProposalFixture::HW_TOPIC]);
        $this->fixture->heartbeat();
    }

    public function testSwitchBackCannotReviveAnExpiredOtherActuatorChain(): void
    {
        $fixture = $this->fixture;
        $gateway = new RecordingTelegramGateway();
        [$sim, $simBot] = $fixture->stack(ProposalFixture::SIM_TOPIC, $gateway);
        [$hardware, $hardwareBot] = $fixture->stack(ProposalFixture::HW_TOPIC, $gateway);
        $simBot->evaluateAndNotify();
        $old = $gateway->sent[0][1];
        $fixture->expireProposal($old, 1800);
        $hardwareBot->evaluateAndNotify();
        self::assertCount(2, $gateway->sent);
        $fixture->expireProposal($gateway->sent[1][1], 1800);
        $fixture->heartbeat();
        $simBot->evaluateAndNotify();
        self::assertCount(2, $gateway->sent);
        self::assertSame('expired', $fixture->column('status', $old));
        self::assertSame('ignored', $sim->decide($old, 'approve'));
        self::assertSame([], $fixture->publisher->calls);
    }

    private function legacyRow(string $notification, ?int $messageId): int
    {
        $db = $this->fixture->harness->connection;
        $db->insert('watering_proposal', ['device_id' => $this->fixture->device,
            'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800), 'duration_seconds' => 3,
            'readings_json' => json_encode([['value' => 14.0, 'measured_at' => gmdate('Y-m-d H:i:s')]], JSON_THROW_ON_ERROR),
            'notification_status' => $notification, 'message_id' => $messageId]);
        return (int) $db->lastInsertId();
    }
}