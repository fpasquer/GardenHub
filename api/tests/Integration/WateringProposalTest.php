<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WateringProposal;
use App\Tests\Support\Command;
use App\Tests\Support\LockWaitProof;
use App\Tests\Support\ProposalFixture;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testExpiredProposalsRenewIndefinitelyAfterExpirationDelay(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        for ($index = 0; $index < 4; ++$index) {
            $fixture->harness->connection->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
            $fixture->bot->evaluateAndNotify();
            self::assertCount($index + 1, $fixture->telegram->sent);
            if ($index > 0) {
                self::assertStringContainsString('renewed watering request', $fixture->telegram->sent[$index][0]);
            }
            $id = $fixture->telegram->sent[$index][1];
            $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 MINUTE) WHERE id = ?', [$id]);
            $fixture->bot->evaluateAndNotify();
            self::assertSame('expired', $fixture->column('status', $id));
            self::assertCount($index + 1, $fixture->telegram->sent);
            $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?', [$id]);
        }
        self::assertCount(4, array_unique(array_column($fixture->telegram->sent, 1)));
        self::assertSame([], $fixture->publisher->calls);
    }

    public function testTemporarySafetyFailurePreservesExpiredChainUntilRecovery(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $id = $fixture->telegram->sent[0][1];
        $fixture->expireProposal($id, 1800);
        $expiry = $fixture->column('expires_at', $id);
        $fixture->harness->connection->executeStatement('UPDATE watering_control SET monitor_seen_at = NULL WHERE id = 1');
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        for ($pass = 0; $pass < 3; ++$pass) {
            $restarted->evaluateAndNotify();
            self::assertCount(1, $fixture->telegram->sent);
            self::assertSame('expired', $fixture->column('status', $id));
            self::assertSame($expiry, $fixture->column('expires_at', $id));
        }
        $fixture->heartbeat();
        $restarted->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertSame([], $fixture->publisher->calls);
    }

    public function testRestartRetainsCustomRetryAndOldCallbacksCannotAffectSuccessor(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $id = $fixture->telegram->sent[0][1];
        $fixture->expireProposal($id, 90);
        [$policy, $bot] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram, 120);
        $bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $fixture->expireProposal($id, 120);
        $fixture->heartbeat();
        $bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        $next = $fixture->telegram->sent[1][1];
        self::assertSame('ignored', $policy->decide($id, 'approve'));
        self::assertSame('ignored', $policy->decide($id, 'reject'));
        self::assertSame('pending', $fixture->column('status', $next));
        self::assertSame('rejected', $policy->decide($next, 'reject'));
        self::assertNull($policy->evaluate());
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET decided_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ?', [$next]);
        $bot->evaluateAndNotify();
        self::assertCount(3, $fixture->telegram->sent);
        self::assertStringNotContainsString('renewed', $fixture->telegram->sent[2][0]);
    }

    public function testRecoveryAndStaleMeasurementsPreventRenewals(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $fixture->expireProposal($fixture->telegram->sent[0][1], 1800);
        $fixture->readings([100, 80, 60]);
        $fixture->bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $fixture->insert(18, 0);
        $fixture->bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertStringNotContainsString('renewed', $fixture->telegram->sent[1][0]);
    }

    public function testUncertainDeliveryIsNotResentButCanRenewAfterExpiration(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->telegram->failSend = true;
        $fixture->bot->evaluateAndNotify();
        $id = $fixture->telegram->sent[0][1];
        self::assertSame('uncertain', $fixture->column('notification_status', $id));
        $fixture->bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $fixture->expireProposal($id, 1800);
        $fixture->telegram->failSend = false;
        $fixture->heartbeat();
        $fixture->bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertSame('sent', $fixture->column('notification_status', $fixture->telegram->sent[1][1]));
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalSuccessorCannotResurrectExpiredAncestor(string $status): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $ancestor = $fixture->telegram->sent[0][1];
        $fixture->expireProposal($ancestor, 1800);
        $fixture->heartbeat();
        $fixture->bot->evaluateAndNotify();
        $successor = $fixture->telegram->sent[1][1];
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET status = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $successor]);
        $fixture->expireProposal($successor, 1800);
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restarted->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertSame('expired', $fixture->column('status', $ancestor));
        self::assertSame($status, $fixture->column('status', $successor));
        self::assertSame([], $fixture->publisher->calls);
    }

    public static function terminalStatuses(): array
    {
        return [['approved'], ['rejected'], ['executing'], ['failed'], ['uncertain'], ['invalidated']];
    }

    public function testApprovedAndCompletedWateringEndsRenewalChain(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $id = $fixture->telegram->sent[0][1];
        $fixture->expireProposal($id, 1800);
        $fixture->heartbeat();
        $fixture->bot->evaluateAndNotify();
        $renewal = $fixture->telegram->sent[1][1];
        $fixture->bot->process(ProposalFixture::callback(100, $id));
        $fixture->bot->process(ProposalFixture::callback(101, $id, action: 'r'));
        self::assertSame('pending', $fixture->column('status', $renewal));
        $fixture->heartbeat();
        $fixture->bot->process(ProposalFixture::callback(102, $renewal));
        self::assertSame('approved', $fixture->column('status', $renewal));
        $fixture->watering->observe('{"state":"ON"}');
        $fixture->watering->observe('{"state":"OFF"}');
        self::assertSame('completed', $fixture->watering->latest()?->getStatus());
        $fixture->expireProposal($renewal, 1800);
        $fixture->bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        $fixture->harness->connection->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR)');
        $fixture->harness->connection->executeStatement('UPDATE watering_control SET last_request_at = NULL WHERE id = 1');
        $fixture->heartbeat();
        $fixture->bot->evaluateAndNotify();
        self::assertCount(3, $fixture->telegram->sent);
        self::assertStringNotContainsString('renewed', $fixture->telegram->sent[2][0]);
        self::assertCount(1, $fixture->publisher->calls);
    }

    public function testRestartAfterLongDowntimeCreatesOneCurrentRenewal(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $fixture->expireProposal($fixture->telegram->sent[0][1], 172800);
        [$policy, $bot] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $policy->expire();
        $fixture->heartbeat();
        $successor = $policy->evaluate();
        self::assertInstanceOf(WateringProposal::class, $successor);
        self::assertGreaterThan(time(), $successor->getExpiresAt()->getTimestamp());
        [, $restarted] = $fixture->stack(ProposalFixture::SIM_TOPIC, $fixture->telegram);
        $restarted->evaluateAndNotify();
        $bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertSame($successor->getId(), $fixture->telegram->sent[1][1]);
        self::assertStringContainsString('renewed', $fixture->telegram->sent[1][0]);
    }

    public function testRetryConfigurationFailsClosed(): void
    {
        foreach ([0, -1, '', 'abc', '1.5', '999999999999999999999'] as $value) {
            try {
                $this->fixture->stack(ProposalFixture::SIM_TOPIC, $this->fixture->telegram, $value);
                self::fail('Invalid retry configuration accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('retry', $error->getMessage());
            }
        }
        self::assertSame([], $this->fixture->telegram->sent);
    }

    public function testActiveAndBlockedWateringCannotRenewEvenAfterAttemptGuard(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $id = $fixture->telegram->sent[0][1];
        $fixture->expireProposal($id, 1800);
        $db = $fixture->harness->connection;
        $fixture->heartbeat();
        $run = $fixture->watering->request(3);
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = ?', [$run]);
        $db->executeStatement('UPDATE watering_control SET last_request_at = NULL WHERE id = 1');
        foreach (['running', 'pending', 'uncertain', 'timed_out'] as $status) {
            $db->executeStatement('UPDATE watering_run SET status = ? WHERE id = ?', [$status, $run]);
            $fixture->heartbeat();
            $fixture->bot->evaluateAndNotify();
            self::assertCount(1, $fixture->telegram->sent);
            self::assertSame('expired', $fixture->column('status', $id));
        }
        $db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = UTC_TIMESTAMP() WHERE id = 1');
        $fixture->bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $db->executeStatement('UPDATE watering_control SET last_request_at = NULL WHERE id = 1');
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR), requested_seconds = 120 WHERE id = ?', [$run]);
        $fixture->bot->evaluateAndNotify();
        self::assertCount(1, $fixture->telegram->sent);
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = ?', [$run]);
        $fixture->heartbeat();
        $fixture->bot->evaluateAndNotify();
        self::assertCount(2, $fixture->telegram->sent);
        self::assertCount(1, $fixture->publisher->calls);
    }

    public function testConcurrentEvaluatorsCreateAndNotifyOneRenewal(): void
    {
        $fixture = $this->fixture;
        $fixture->readings();
        $fixture->bot->evaluateAndNotify();
        $fixture->expireProposal($fixture->telegram->sent[0][1], 1800);
        $workspace = new TemporaryWorkspace();
        $db = $fixture->harness->connection;
        $parent = (int) $db->fetchOne('SELECT CONNECTION_ID()');
        $children = [];
        $requesting = $parent;
        $db->beginTransaction();
        try {
            $db->fetchOne('SELECT id FROM device WHERE id = ? FOR UPDATE', [$fixture->device]);
            for ($index = 0; $index < 2; ++$index) {
                $path = sprintf('%s/evaluator-%d.json', $workspace->path, $index);
                $child = new Process([PHP_BINARY, Command::root().'/tests/watering-control/proposal-approve-child.php', '--notify', $path], '/app', timeout: 30);
                $child->start();
                $children[] = $child;
                try {
                    $connectionId = LockWaitProof::connectionId($path);
                    LockWaitProof::wait($db, $connectionId, $requesting);
                    $requesting = $connectionId;
                } catch (\RuntimeException $error) {
                    self::fail(sprintf('Evaluator %d: %s %s %s', $index, $error->getMessage(), $child->getErrorOutput(), json_encode($db->fetchAllAssociative('SHOW PROCESSLIST'))));
                }
            }
            $db->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
            $db->commit();
            $sent = [];
            foreach ($children as $child) {
                self::assertSame(0, $child->wait(), $child->getErrorOutput());
                $sent = array_merge($sent, json_decode($child->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            }
            self::assertCount(1, $sent);
            self::assertStringContainsString('renewed watering request', $sent[0][0]);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM watering_proposal'));
            self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM watering_proposal WHERE status = 'pending' AND notification_status = 'sent'"));
            self::assertSame(0, $fixture->runCount());
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            foreach ($children as $child) {
                $child->stop();
            }
        }
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
        $fixture->harness->connection->executeStatement('UPDATE watering_proposal SET decided_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ?', [$proposal->getId()]);
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