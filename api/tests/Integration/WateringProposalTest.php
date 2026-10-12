<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringProposal;
use App\Tests\Support\Command;
use App\Tests\Support\ProposalFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('functional_gardenhub-watering-proposals')]
final class WateringProposalTest extends TestCase
{
    private ProposalFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ProposalFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->harness->shutdown();
    }

    public function testProposalDurationMustFitConfiguredSafetyLimit(): void
    {
        \newTestPolicy($this->fixture->harness->manager,
            \newTestManager($this->fixture->harness->manager, $this->fixture->publisher,
                true, 'dev', 67, 134, 1800), ProposalFixture::SIM_TOPIC, 67);
        $this->expectException(\LogicException::class);
        \newTestPolicy($this->fixture->harness->manager, $this->fixture->watering,
            ProposalFixture::SIM_TOPIC, 67);
    }

    public function testTriggerNotificationRecoveryRejectionReminderAndApprovalHistory(): void
    {
        $proposal = $this->triggerAndRecoverNotification();
        $this->rejectAndRemind($proposal);
        $fresh = $this->recoverDryEpisode();
        $this->approveOnceAndCheckDailyBoundary($fresh);
        $this->raceIndependentApprovals();
        $this->checkStaleGapFailureAndPollingOffset();
    }

    private function triggerAndRecoverNotification(): WateringProposal
    {
        $fixture = $this->fixture;
        $fixture->insert(14, 40);
        $fixture->insert(14, 20);
        self::assertNull($fixture->policy->evaluate());
        $fixture->insert(14, 0);
        $proposal = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $proposal);
        self::assertSame('new', $fixture->column('notification_status', $proposal->getId()));
        [, $restartedBot] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restartedBot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        self::assertSame($proposal->getId(), $fixture->telegram->sent[0][1]);
        self::assertStringContainsString('Actuator: configured MQTT actuator ('.ProposalFixture::SIM_TOPIC.')', $fixture->telegram->sent[0][0]);
        self::assertStringNotContainsString('physical pump', $fixture->telegram->sent[0][0]);
        self::assertSame(ProposalFixture::SIM_TOPIC, $proposal->getActuatorTopic());
        self::assertSame('sent', $fixture->column('notification_status', $proposal->getId()));
        $restartedBot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        self::assertNull($fixture->policy->evaluate());
        self::assertFalse($fixture->policy->claimNotification($proposal->getId()));
        self::assertCount(3, $proposal->getReadingsJson());
        return $proposal;
    }

    private function rejectAndRemind(WateringProposal $proposal): void
    {
        $fixture = $this->fixture;
        $fixture->bot->process(ProposalFixture::callback(1, $proposal->getId(), 999));
        $fixture->bot->process(ProposalFixture::callback(2, $proposal->getId(), 123, 999));
        $fixture->bot->process(ProposalFixture::callback(3, $proposal->getId(), 123, 456, 'group'));
        self::assertSame('pending', $fixture->column('status', $proposal->getId()));
        self::assertCount(3, $fixture->telegram->acks);
        $fixture->bot->process(ProposalFixture::callback(4, $proposal->getId(), 123, 456, 'private', 'r'));
        $fixture->bot->process(ProposalFixture::callback(4, $proposal->getId()));
        self::assertSame('rejected', $fixture->column('status', $proposal->getId()));
        self::assertSame([], $fixture->publisher->calls);
        $fixture->bot->reconcileMessages();
        self::assertCount(1, $fixture->telegram->edits);
        self::assertNull($fixture->policy->evaluate());
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal_state SET last_prompt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE device_id = ?', [$fixture->device]);
        $reminder = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $reminder);
        self::assertNull($fixture->policy->evaluate());
        self::assertTrue($fixture->policy->claimNotification($reminder->getId()));
        $fixture->telegram->send('fixture', $reminder->getId());
        $sent = count($fixture->telegram->sent);
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restarted->evaluateAndNotify();
        self::assertCount($sent, $fixture->telegram->sent);
        self::assertSame('sending', $fixture->column('notification_status', $reminder->getId()));
        $fixture->policy->notificationUncertain($reminder->getId());
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restarted->evaluateAndNotify();
        self::assertCount($sent, $fixture->telegram->sent);
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?', [$reminder->getId()]);
        self::assertSame('expired', $fixture->policy->decide($reminder->getId(), 'approve'));
        self::assertNull($fixture->policy->evaluate());
    }

    private function recoverDryEpisode(): WateringProposal
    {
        $fixture = $this->fixture;
        $fixture->insert(18, 0);
        self::assertNull($fixture->policy->evaluate());
        $fixture->insert(14, -1);
        self::assertNull($fixture->policy->evaluate());
        $fixture->harness->connection->executeStatement('DELETE FROM measurement WHERE measured_at > UTC_TIMESTAMP()');
        $fixture->insert(14, 0);
        $fixture->insert(14, 0);
        self::assertNull($fixture->policy->evaluate());
        $fixture->readings();
        $fresh = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $fresh);
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?', [$fresh->getId()]);
        return $fresh;
    }

    private function approveOnceAndCheckDailyBoundary(WateringProposal $proposal): void
    {
        $fixture = $this->fixture;
        $db = $fixture->harness->connection;
        $fixture->bot->process(ProposalFixture::callback(5, $proposal->getId()));
        self::assertSame('approved', $fixture->column('status', $proposal->getId()));
        self::assertCount(1, $fixture->publisher->calls);
        self::assertSame(1, $fixture->runCount());
        $fixture->bot->process(ProposalFixture::callback(6, $proposal->getId()));
        self::assertCount(1, $fixture->publisher->calls);
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) WHERE id = (SELECT id FROM (SELECT id FROM watering_run LIMIT 1) t)');
        $db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
        $fixture->insert(18, 0);
        $fixture->policy->evaluate();
        $fixture->readings();
        $boundary = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $boundary);
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR)');
        self::assertSame('failed', $fixture->policy->decide($boundary->getId(), 'approve'));
        $db->executeStatement("UPDATE watering_proposal SET status = 'executing' WHERE id = ?", [$boundary->getId()]);
        $fixture->policy->expire();
        self::assertSame('uncertain', $fixture->column('status', $boundary->getId()));
        self::assertSame('ignored', $fixture->policy->decide($boundary->getId(), 'approve'));
    }

    private function raceIndependentApprovals(): void
    {
        $fixture = $this->fixture;
        $fixture->nextEpisode();
        $race = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $race);
        $before = $fixture->runCount();
        $children = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $child = new Process([PHP_BINARY, Command::root().'/tests/watering-control/proposal-approve-child.php', (string) $race->getId()], '/app', timeout: 30);
                $child->start();
                $children[] = $child;
            }
            $results = [];
            foreach ($children as $child) {
                $child->wait();
                self::assertSame(0, $child->getExitCode(), substr($child->getErrorOutput(), -65536));
                $results[] = $child->getOutput();
            }
            sort($results);
            self::assertSame(['approved', 'ignored'], $results);
            self::assertSame($before + 1, $fixture->runCount());
        } finally {
            foreach ($children as $child) {
                $child->stop();
            }
        }
    }

    private function checkStaleGapFailureAndPollingOffset(): void
    {
        $fixture = $this->fixture;
        $fixture->nextEpisode();
        $fixture->readings([100, 80, 60]);
        self::assertNull($fixture->policy->evaluate());
        $fixture->readings([80, 20, 0]);
        self::assertNull($fixture->policy->evaluate());
        $fixture->readings();
        $failed = $fixture->policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $failed);
        $fixture->publisher->fail = true;
        self::assertSame('uncertain', $fixture->policy->decide($failed->getId(), 'approve'));
        self::assertSame('uncertain', $fixture->harness->connection->fetchOne('SELECT status FROM watering_run ORDER BY requested_at DESC, id DESC LIMIT 1'));
        self::assertSame('ignored', $fixture->policy->decide($failed->getId(), 'approve'));
        $fixture->telegram->queue[] = ProposalFixture::callback(20, $failed->getId());
        $fixture->bot->pollOnce();
        self::assertSame(21, (int) $fixture->harness->connection->fetchOne('SELECT next_update_id FROM watering_telegram_progress WHERE id = 1'));
        $acks = count($fixture->telegram->acks);
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restarted->pollOnce();
        self::assertCount($acks, $fixture->telegram->acks);
    }
}