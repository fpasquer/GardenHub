<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\ProposalBot;
use App\Watering\ProposalPolicy;
use App\Watering\TelegramGateway;
use App\Watering\WateringManager;
use App\Watering\WateringPublisher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';

final class ProposalFakePublisher implements WateringPublisher
{
    public int $calls = 0;
    public bool $fail = false;
    public function publish(array $command): void
    {
        ++$this->calls;
        if ($this->fail) {
            throw new RuntimeException('Fake broker failure');
        }
    }
}

final class ProposalFakeTelegram implements TelegramGateway
{
    public array $sent = [];
    public array $acks = [];
    public array $edits = [];
    public array $queue = [];
    public function send(string $text, string $proposalId): int { $this->sent[] = [$text, $proposalId]; return count($this->sent); }
    public function updates(int $offset): array { return array_values(array_filter($this->queue, static fn (array $u): bool => $u['update_id'] >= $offset)); }
    public function acknowledge(string $callbackId): void { $this->acks[] = $callbackId; }
    public function edit(int $messageId, string $text): void { $this->edits[] = [$messageId, $text]; }
}

function ok(bool $yes, string $message): void { if (!$yes) { throw new RuntimeException($message); } }
function callback(int $updateId, string $proposalId, int $user = 123, int $chat = 456, string $type = 'private', string $action = 'a'): array
{
    return ['update_id' => $updateId, 'callback_query' => ['id' => 'cb'.$updateId, 'from' => ['id' => $user], 'message' => ['chat' => ['id' => $chat, 'type' => $type]], 'data' => 'w:'.$action.':'.$proposalId]];
}

$kernel = new Kernel('dev', true);
$kernel->boot();
/** @var Connection $db */
$db = $kernel->getContainer()->get('doctrine')->getManager()->getConnection();
ok($db->getDatabase() === 'watering_control_test', 'Unexpected database');
$db->executeStatement('DELETE FROM watering_proposal');
$db->executeStatement('DELETE FROM watering_proposal_state');
$db->executeStatement('DELETE FROM watering_run');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
$db->executeStatement('UPDATE watering_telegram_progress SET next_update_id = 0 WHERE id = 1');
$db->insert('device', ['name' => 'SE01-Avocado', 'created_at' => gmdate('Y-m-d H:i:s')]);
$deviceId = (int) $db->lastInsertId();
$db->insert('sensor', ['device_id' => $deviceId, 'type' => 'soil_moisture', 'unit' => '%', 'created_at' => gmdate('Y-m-d H:i:s')]);
$sensorId = (int) $db->lastInsertId();
$insert = static function (float $value, int $ageMinutes) use ($db, $sensorId): void {
    $db->insert('measurement', ['sensor_id' => $sensorId, 'value' => $value, 'type' => 'soil_moisture', 'deduplication_id' => (string) Uuid::v4(), 'measured_at' => gmdate('Y-m-d H:i:s', time() - $ageMinutes * 60), 'created_at' => gmdate('Y-m-d H:i:s')]);
};
$publisher = new ProposalFakePublisher();
$watering = new WateringManager($db, $publisher, true, 'dev');
$policy = new ProposalPolicy($db, $watering, 15, 35, 35, 30, 3);
$telegram = new ProposalFakeTelegram();
$bot = new ProposalBot($db, $policy, $telegram, '123', '456');

$insert(14, 40);
$insert(14, 20);
ok($policy->evaluate() === null, 'Two readings triggered');
$insert(14, 0);
$p = $policy->evaluate();
ok(is_array($p), 'Three fresh consecutive readings did not trigger');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$p['id']]) === 'new', 'Proposal was not persisted before notification');
// Simulate a restart after evaluate() committed but before the Telegram claim.
$restartedPolicy = new ProposalPolicy($db, new WateringManager($db, $publisher, true, 'dev'), 15, 35, 35, 30, 3);
$restartedBot = new ProposalBot($db, $restartedPolicy, $telegram, '123', '456');
$restartedBot->evaluateAndNotify();
ok(count($telegram->sent) === 1 && $telegram->sent[0][1] === $p['id'], 'Restart did not send the persisted proposal exactly once');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$p['id']]) === 'sent', 'Recovered notification was not recorded');
$restartedBot->evaluateAndNotify();
ok(count($telegram->sent) === 1, 'Replay resent a recorded notification');
ok($policy->evaluate() === null, 'Duplicate proposal created');
ok(!$policy->claimNotification($p['id']), 'Recorded notification was claimed again');
ok(count(json_decode($p['readings_json'], true)) === 3, 'Snapshot must contain three values');

$bot->process(callback(1, $p['id'], 999));
$bot->process(callback(2, $p['id'], 123, 999));
$bot->process(callback(3, $p['id'], 123, 456, 'group'));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$p['id']]) === 'pending', 'Unauthorized callback changed proposal');
ok(count($telegram->acks) === 3, 'Unauthorized callbacks were not acknowledged');
$bot->process(callback(4, $p['id'], 123, 456, 'private', 'r'));
$bot->process(callback(4, $p['id'], 123, 456, 'private', 'a'));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$p['id']]) === 'rejected' && $publisher->calls === 0, 'Reject/replay started watering');
$bot->reconcileMessages();
ok(count($telegram->edits) === 1, 'Final message was not edited');
ok($policy->evaluate() === null, 'Rejection prompted again immediately');
$db->executeStatement('UPDATE watering_proposal_state SET last_prompt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE device_id = ?', [$deviceId]);
$reminder = $policy->evaluate();
ok(is_array($reminder), 'Bounded reminder missing');
ok($policy->evaluate() === null, 'Duplicate reminder created');
ok($policy->claimNotification($reminder['id']), 'Reminder notification claim failed');
$telegram->send('fixture', $reminder['id']); // Telegram may receive it before the process records the message ID.
$sentBeforeRestart = count($telegram->sent);
(new ProposalBot($db, $restartedPolicy, $telegram, '123', '456'))->evaluateAndNotify();
ok(count($telegram->sent) === $sentBeforeRestart, 'Restart resent a possibly successful notification');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$reminder['id']]) === 'sending', 'Restart changed an in-flight notification');
$policy->notificationUncertain($reminder['id']);
(new ProposalBot($db, $restartedPolicy, $telegram, '123', '456'))->evaluateAndNotify();
ok(count($telegram->sent) === $sentBeforeRestart, 'Restart resent an uncertain notification');
$db->executeStatement("UPDATE watering_proposal SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?", [$reminder['id']]);
ok($policy->decide($reminder['id'], 'approve') === 'expired', 'Expired proposal approved');
ok($policy->evaluate() === null, 'More than one reminder prompted');

$insert(18, 0);
ok($policy->evaluate() === null, 'Wet reading did not reset episode');
$insert(14, -1); // Future reading must be rejected.
ok($policy->evaluate() === null, 'Future reading accepted');
$db->executeStatement('DELETE FROM measurement WHERE measured_at > UTC_TIMESTAMP()');
$insert(14, 0);
$insert(14, 0);
ok($policy->evaluate() === null, 'Duplicate timestamp counted as a distinct reading');
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 40);
$insert(14, 20);
$insert(14, 0);
$fresh = $policy->evaluate();
ok(is_array($fresh), 'Recovery did not begin a new dry episode');
$db->executeStatement("UPDATE watering_proposal SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?", [$fresh['id']]);

$bot->process(callback(5, $fresh['id']));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$fresh['id']]) === 'approved', 'Approval failed');
ok($publisher->calls === 1 && (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run') === 1, 'Approval did not reserve exactly one run');
$bot->process(callback(6, $fresh['id']));
ok($publisher->calls === 1, 'Replayed approval started another run');

// At exactly 24 hours the attempt is eligible; one second inside is not.
$db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) WHERE id = (SELECT id FROM (SELECT id FROM watering_run LIMIT 1) t)');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
$insert(18, 0);
$policy->evaluate();
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 40);
$insert(14, 20);
$insert(14, 0);
$boundary = $policy->evaluate();
ok(is_array($boundary), '24-hour boundary rejected');
$db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR)');
ok($policy->decide($boundary['id'], 'approve') === 'failed', 'Recent attempt did not block approval');

// The DB claim survives a crash before request(): restart records uncertainty.
$db->executeStatement("UPDATE watering_proposal SET status = 'executing' WHERE id = ?", [$boundary['id']]);
$policy->expire();
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$boundary['id']]) === 'uncertain', 'Restart did not close interrupted execution');
ok($policy->decide($boundary['id'], 'approve') === 'ignored', 'Interrupted approval was retried');

// Two independent PHP processes race on the same proposal row. Exactly one
// may reserve a run; the other must observe the durable claim.
$db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR)');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
$insert(18, 0);
$policy->evaluate();
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 40);
$insert(14, 20);
$insert(14, 0);
$race = $policy->evaluate();
ok(is_array($race), 'Concurrency fixture missing proposal');
$before = (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run');
$children = [];
for ($i = 0; $i < 2; ++$i) {
    $process = proc_open(['php', '/tests/proposal-approve-child.php', $race['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ok(is_resource($process), 'Could not launch approval worker');
    $children[] = [$process, $pipes];
}
$results = [];
foreach ($children as [$process, $pipes]) {
    $results[] = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    ok(proc_close($process) === 0, 'Approval worker failed: '.$error);
}
sort($results);
ok($results === ['approved', 'ignored'], 'Concurrent callbacks did not resolve once');
ok((int) $db->fetchOne('SELECT COUNT(*) FROM watering_run') === $before + 1, 'Concurrent approval reserved multiple runs');

$db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR)');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
$insert(18, 0);
$policy->evaluate();
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 100); $insert(14, 80); $insert(14, 60);
ok($policy->evaluate() === null, 'Stale latest reading triggered');
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 80); $insert(14, 20); $insert(14, 0);
ok($policy->evaluate() === null, 'Unreasonable gap triggered');
$db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
$insert(14, 40); $insert(14, 20); $insert(14, 0);
$failed = $policy->evaluate();
ok(is_array($failed), 'Failure fixture missing proposal');
$publisher->fail = true;
ok($policy->decide($failed['id'], 'approve') === 'uncertain', 'Failed publish was not uncertain');
ok($db->fetchOne("SELECT status FROM watering_run ORDER BY requested_at DESC, id DESC LIMIT 1") === 'uncertain', 'Failed publish did not retain uncertain run');
ok($policy->decide($failed['id'], 'approve') === 'ignored', 'Failed approval was retried');

// The offset is durable across a new bot instance. Reprocessing after a crash
// before saving it is also safe because the decision is already terminal.
$telegram->queue[] = callback(20, $failed['id']);
$bot->pollOnce();
ok((int) $db->fetchOne('SELECT next_update_id FROM watering_telegram_progress WHERE id = 1') === 21, 'Polling offset was not persisted');
$acks = count($telegram->acks);
(new ProposalBot($db, $policy, $telegram, '123', '456'))->pollOnce();
ok(count($telegram->acks) === $acks, 'Restart redelivered an acknowledged update');

echo "PASS proposal trigger, duplicate/stale/gap/recovery, reminder/expiry, authorization, replay, concurrent approval, 24-hour boundary, uncertain delivery, restart and polling offset\n";
$kernel->shutdown();
