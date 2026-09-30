<?php

declare(strict_types=1);

use App\Kernel;
use App\Watering\MqttWateringPublisher;
use App\Entity\WateringProposal;
use App\Watering\InterfaceTelegramGateway;
use App\Watering\InterfaceWateringPublisher;
use Symfony\Component\Uid\Uuid;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

const SIM_TOPIC = MqttWateringPublisher::DEFAULT_TOPIC;
const HW_TOPIC = 'zigbee2mqtt/avocado-watering';

final class ProposalFakePublisher implements InterfaceWateringPublisher
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

final class ProposalFakeTelegram implements InterfaceTelegramGateway
{
    public array $sent = [];
    public array $acks = [];
    public array $edits = [];
    public array $queue = [];
    public function send(string $text, int $proposalId): int { $this->sent[] = [$text, $proposalId]; return count($this->sent); }
    public function updates(int $offset): array { return array_values(array_filter($this->queue, static fn (array $u): bool => $u['update_id'] >= $offset)); }
    public function acknowledge(string $callbackId): void { $this->acks[] = $callbackId; }
    public function edit(int $messageId, string $text): void { $this->edits[] = [$messageId, $text]; }
}

function ok(bool $yes, string $message): void { if (!$yes) { throw new RuntimeException($message); } }
function callback(int $updateId, int $proposalId, int $user = 123, int $chat = 456, string $type = 'private', string $action = 'a'): array
{
    return ['update_id' => $updateId, 'callback_query' => ['id' => 'cb'.$updateId, 'from' => ['id' => $user], 'message' => ['chat' => ['id' => $chat, 'type' => $type]], 'data' => 'w:'.$action.':'.$proposalId]];
}

$kernel = new Kernel('dev', true);
$em = bootTestEntityManager($kernel);
$db = $em->getConnection();
clearWateringRuns($db);
$db->executeStatement('DELETE FROM watering_proposal_state');
$db->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
$db->executeStatement('UPDATE watering_telegram_progress SET next_update_id = 0 WHERE id = 1');
$db->insert('device', ['name' => 'SE01-Avocado', 'created_at' => gmdate('Y-m-d H:i:s')]);
$deviceId = (int) $db->lastInsertId();
$db->insert('sensor', ['device_id' => $deviceId, 'type' => 'soil_moisture', 'unit' => '%', 'created_at' => gmdate('Y-m-d H:i:s')]);
$sensorId = (int) $db->lastInsertId();
$insert = static function (float $value, int $ageMinutes) use ($db, $sensorId): void {
    $db->insert('measurement', ['sensor_id' => $sensorId, 'value' => $value, 'type' => 'soil_moisture', 'deduplication_id' => (string) Uuid::v4(), 'measured_at' => gmdate('Y-m-d H:i:s', time() - $ageMinutes * 60), 'created_at' => gmdate('Y-m-d H:i:s')]);
};
$publisher = new ProposalFakePublisher();
$watering = newTestManager($em, $publisher);
$invalidDurationRejected = false;
try {
    newTestPolicy($em, $watering, SIM_TOPIC, 67);
} catch (LogicException) {
    $invalidDurationRejected = true;
}
ok($invalidDurationRejected, 'Proposal duration may exceed the configured per-run safety limit');
newTestPolicy($em, newTestManager($em, $publisher, true, 'dev', 67, 134, 1800), SIM_TOPIC, 67);
$policy = newTestPolicy($em, $watering, SIM_TOPIC);
$telegram = new ProposalFakeTelegram();
$bot = newTestBot($em, $policy, $telegram);

$insert(14, 40);
$insert(14, 20);
ok($policy->evaluate() === null, 'Two readings triggered');
$insert(14, 0);
$p = $policy->evaluate();
ok($p instanceof WateringProposal, 'Three fresh consecutive readings did not trigger');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$p->getId()]) === 'new', 'Proposal was not persisted before notification');
// Restart with the same actuator after evaluate() committed but before the Telegram claim.
$restartedPolicy = newTestPolicy($em, newTestManager($em, $publisher), SIM_TOPIC);
$restartedBot = newTestBot($em, $restartedPolicy, $telegram);
$restartedBot->evaluateAndNotify();
ok(count($telegram->sent) === 1 && $telegram->sent[0][1] === $p->getId(), 'Restart did not send the persisted proposal exactly once');
ok(str_contains($telegram->sent[0][0], 'Actuator: configured MQTT actuator ('.SIM_TOPIC.')') && !str_contains($telegram->sent[0][0], 'physical pump'), 'Restarted text does not use the proposal\'s stored topic');
ok($p->getActuatorTopic() === SIM_TOPIC, 'Proposal did not persist its actuator topic');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$p->getId()]) === 'sent', 'Recovered notification was not recorded');
$restartedBot->evaluateAndNotify();
ok(count($telegram->sent) === 1, 'Replay resent a recorded notification');
ok($policy->evaluate() === null, 'Duplicate proposal created');
ok(!$policy->claimNotification($p->getId()), 'Recorded notification was claimed again');
ok(count($p->getReadingsJson()) === 3, 'Snapshot must contain three values');

$bot->process(callback(1, $p->getId(), 999));
$bot->process(callback(2, $p->getId(), 123, 999));
$bot->process(callback(3, $p->getId(), 123, 456, 'group'));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$p->getId()]) === 'pending', 'Unauthorized callback changed proposal');
ok(count($telegram->acks) === 3, 'Unauthorized callbacks were not acknowledged');
$bot->process(callback(4, $p->getId(), 123, 456, 'private', 'r'));
$bot->process(callback(4, $p->getId(), 123, 456, 'private', 'a'));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$p->getId()]) === 'rejected' && $publisher->calls === 0, 'Reject/replay started watering');
$bot->reconcileMessages();
ok(count($telegram->edits) === 1, 'Final message was not edited');
ok($policy->evaluate() === null, 'Rejection prompted again immediately');
$db->executeStatement('UPDATE watering_proposal_state SET last_prompt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE device_id = ?', [$deviceId]);
$reminder = $policy->evaluate();
ok($reminder instanceof WateringProposal, 'Bounded reminder missing');
ok($policy->evaluate() === null, 'Duplicate reminder created');
ok($policy->claimNotification($reminder->getId()), 'Reminder notification claim failed');
$telegram->send('fixture', $reminder->getId()); // Telegram may receive it before the process records the message ID.
$sentBeforeRestart = count($telegram->sent);
(newTestBot($em, $restartedPolicy, $telegram))->evaluateAndNotify();
ok(count($telegram->sent) === $sentBeforeRestart, 'Restart resent a possibly successful notification');
ok($db->fetchOne('SELECT notification_status FROM watering_proposal WHERE id = ?', [$reminder->getId()]) === 'sending', 'Restart changed an in-flight notification');
$policy->notificationUncertain($reminder->getId());
(newTestBot($em, $restartedPolicy, $telegram))->evaluateAndNotify();
ok(count($telegram->sent) === $sentBeforeRestart, 'Restart resent an uncertain notification');
$db->executeStatement("UPDATE watering_proposal SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?", [$reminder->getId()]);
ok($policy->decide($reminder->getId(), 'approve') === 'expired', 'Expired proposal approved');
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
ok($fresh instanceof WateringProposal, 'Recovery did not begin a new dry episode');
$db->executeStatement("UPDATE watering_proposal SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?", [$fresh->getId()]);

$bot->process(callback(5, $fresh->getId()));
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$fresh->getId()]) === 'approved', 'Approval failed');
ok($publisher->calls === 1 && (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run') === 1, 'Approval did not reserve exactly one run');
$bot->process(callback(6, $fresh->getId()));
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
ok($boundary instanceof WateringProposal, '24-hour boundary rejected');
$db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR)');
ok($policy->decide($boundary->getId(), 'approve') === 'failed', 'Recent attempt did not block approval');

// The DB claim survives a crash before request(): restart records uncertainty.
$db->executeStatement("UPDATE watering_proposal SET status = 'executing' WHERE id = ?", [$boundary->getId()]);
$policy->expire();
ok($db->fetchOne('SELECT status FROM watering_proposal WHERE id = ?', [$boundary->getId()]) === 'uncertain', 'Restart did not close interrupted execution');
ok($policy->decide($boundary->getId(), 'approve') === 'ignored', 'Interrupted approval was retried');

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
ok($race instanceof WateringProposal, 'Concurrency fixture missing proposal');
$before = (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run');
$children = [];
for ($i = 0; $i < 2; ++$i) {
    $process = proc_open(['php', '/tests/proposal-approve-child.php', (string) $race->getId()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
ok($failed instanceof WateringProposal, 'Failure fixture missing proposal');
$publisher->fail = true;
ok($policy->decide($failed->getId(), 'approve') === 'uncertain', 'Failed publish was not uncertain');
ok($db->fetchOne("SELECT status FROM watering_run ORDER BY requested_at DESC, id DESC LIMIT 1") === 'uncertain', 'Failed publish did not retain uncertain run');
ok($policy->decide($failed->getId(), 'approve') === 'ignored', 'Failed approval was retried');

// The offset is durable across a new bot instance. Reprocessing after a crash
// before saving it is also safe because the decision is already terminal.
$telegram->queue[] = callback(20, $failed->getId());
$bot->pollOnce();
ok((int) $db->fetchOne('SELECT next_update_id FROM watering_telegram_progress WHERE id = 1') === 21, 'Polling offset was not persisted');
$acks = count($telegram->acks);
(newTestBot($em, $policy, $telegram))->pollOnce();
ok(count($telegram->acks) === $acks, 'Restart redelivered an acknowledged update');

// Actuator switching: a proposal is bound to the topic it was created for.
$resetFixture = static function () use ($db, $sensorId, $insert, $publisher): void {
    $db->executeStatement('DELETE FROM watering_proposal');
    clearWateringRuns($db);
    $db->executeStatement('DELETE FROM watering_proposal_state');
    $db->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$sensorId]);
    $db->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
    $publisher->fail = false;
    $publisher->calls = 0;
    $insert(14, 40);
    $insert(14, 20);
    $insert(14, 0);
};
$stackFor = static function (string $topic, ProposalFakeTelegram $gateway) use ($em, $publisher): array {
    $stackPolicy = newTestPolicy($em, newTestManager($em, $publisher), $topic);
    return [$stackPolicy, newTestBot($em, $stackPolicy, $gateway)];
};
$column = static fn (string $name, int $id): mixed => $db->fetchOne('SELECT '.$name.' FROM watering_proposal WHERE id = ?', [$id]);
$runs = static fn (): int => (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run');

// A simulator proposal waiting to be sent must never be sent once the hardware topic is configured.
$resetFixture();
$waiting = new ProposalFakeTelegram();
[$simPolicy] = $stackFor(SIM_TOPIC, $waiting);
[$hwPolicy, $hwBot] = $stackFor(HW_TOPIC, $waiting);
$old = $simPolicy->evaluate();
ok($old instanceof WateringProposal && $old->getActuatorTopic() === SIM_TOPIC, 'Simulator proposal did not record its topic');
$hwBot->evaluateAndNotify();
ok($column('status', $old->getId()) === 'invalidated' && $column('failure', $old->getId()) === 'Actuator changed', 'Waiting simulator proposal survived the actuator switch');
ok($column('notification_status', $old->getId()) === 'new' && $column('message_id', $old->getId()) === null, 'Waiting simulator proposal was notified after the switch');
ok(count($waiting->sent) === 1 && $waiting->sent[0][1] !== $old->getId(), 'Only the hardware proposal may be sent');
$hardwareId = $waiting->sent[0][1];
ok($column('actuator_topic', $hardwareId) === HW_TOPIC, 'Hardware proposal did not persist the physical topic');
ok(str_contains($waiting->sent[0][0], 'Actuator: physical pump ('.HW_TOPIC.')') && !str_contains($waiting->sent[0][0], SIM_TOPIC), 'Hardware proposal text does not name the physical topic');
ok($hwPolicy->decide($old->getId(), 'approve') === 'ignored' && $publisher->calls === 0 && $runs() === 0, 'Invalidated waiting proposal was approved');
$hwBot->process(callback(100, $hardwareId));
ok($column('status', $hardwareId) === 'approved' && $publisher->calls === 1 && $runs() === 1, 'Hardware proposal did not follow the normal approval flow');

// An already delivered simulator Approve button must publish nothing and lose its buttons.
$resetFixture();
$delivered = new ProposalFakeTelegram();
[$simPolicy, $simBot] = $stackFor(SIM_TOPIC, $delivered);
[$hwPolicy, $hwBot] = $stackFor(HW_TOPIC, $delivered);
$simBot->evaluateAndNotify();
$oldId = $delivered->sent[0][1];
ok($column('notification_status', $oldId) === 'sent' && (int) $column('message_id', $oldId) === 1, 'Simulator proposal was not delivered');
$hwBot->process(callback(101, $oldId));
ok($column('status', $oldId) === 'invalidated' && $column('failure', $oldId) === 'Actuator changed', 'Old Approve was not invalidated');
ok($publisher->calls === 0 && $runs() === 0 && $delivered->acks === ['cb101'], 'Old Approve published or was not acknowledged');
$hwBot->reconcileMessages();
ok(count($delivered->edits) === 1 && $delivered->edits[0][0] === 1, 'Delivered message was not edited to remove its buttons');
ok(str_contains($delivered->edits[0][1], 'Actuator: configured MQTT actuator ('.SIM_TOPIC.')') && !str_contains($delivered->edits[0][1], 'physical pump') && str_contains($delivered->edits[0][1], 'Decision: invalidated'), 'Old message was relabelled for another actuator');
ok($column('notification_status', $oldId) === 'final', 'Edited message was not marked final');
$hwBot->evaluateAndNotify();
ok(count($delivered->sent) === 2, 'Invalidated simulator proposal blocked a hardware proposal');
$newId = $delivered->sent[1][1];
ok($column('actuator_topic', $newId) === HW_TOPIC && str_contains($delivered->sent[1][0], 'Actuator: physical pump ('.HW_TOPIC.')'), 'New proposal does not name the physical topic');
$hwBot->process(callback(102, $newId));
ok($column('status', $newId) === 'approved' && $publisher->calls === 1 && $runs() === 1, 'Hardware approval after the switch failed');
$counts = $db->fetchAllKeyValue('SELECT actuator_topic, prompt_count FROM watering_proposal_state WHERE device_id = ?', [$deviceId]);
ok($counts === [SIM_TOPIC => 1, HW_TOPIC => 1], 'Prompt counters are not tracked per actuator');

// Each actuator keeps its own one-reminder limit within the same dry episode.
$resetFixture();
[$simPolicy] = $stackFor(SIM_TOPIC, new ProposalFakeTelegram());
[$hwPolicy] = $stackFor(HW_TOPIC, new ProposalFakeTelegram());
ok($simPolicy->evaluate() instanceof WateringProposal, 'Simulator initial prompt missing');
$hwFirst = $hwPolicy->evaluate();
ok($hwFirst instanceof WateringProposal, 'Invalidated simulator proposal consumed the hardware prompt');
ok($hwPolicy->decide($hwFirst->getId(), 'reject') === 'rejected' && $hwPolicy->evaluate() === null, 'Hardware prompt repeated immediately');
$ageHardwarePrompt = static fn () => $db->executeStatement('UPDATE watering_proposal_state SET last_prompt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE actuator_topic = ?', [HW_TOPIC]);
$ageHardwarePrompt();
$hwReminder = $hwPolicy->evaluate();
ok($hwReminder instanceof WateringProposal, 'Hardware reminder missing');
ok($hwPolicy->decide($hwReminder->getId(), 'reject') === 'rejected', 'Hardware reminder was not decided');
$ageHardwarePrompt();
ok($hwPolicy->evaluate() === null, 'Hardware exceeded one initial prompt and one reminder');
ok((int) $db->fetchOne('SELECT prompt_count FROM watering_proposal_state WHERE actuator_topic = ?', [SIM_TOPIC]) === 1, 'Hardware prompts changed the simulator counter');

// Proposals from before the topic was recorded are never approvable and never relabelled.
$resetFixture();
$legacy = new ProposalFakeTelegram();
[$hwPolicy, $hwBot] = $stackFor(HW_TOPIC, $legacy);
$legacyRow = static function (string $notification, ?int $messageId) use ($db, $deviceId): int {
    $db->insert('watering_proposal', [
        'device_id' => $deviceId, 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s'),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800), 'duration_seconds' => 3,
        'readings_json' => json_encode([['value' => 14.0, 'measured_at' => gmdate('Y-m-d H:i:s')]], JSON_THROW_ON_ERROR),
        'notification_status' => $notification, 'message_id' => $messageId,
    ]);
    return (int) $db->lastInsertId();
};
$sentLegacy = $legacyRow('sent', 77);
$unsentLegacy = $legacyRow('new', null);
ok($hwPolicy->decide($sentLegacy, 'approve') === 'invalidated' && $publisher->calls === 0 && $runs() === 0, 'Legacy proposal without a topic was approved');
$hwBot->evaluateAndNotify();
ok($column('status', $unsentLegacy) === 'invalidated' && $column('message_id', $unsentLegacy) === null, 'Legacy notification was sent');
ok(count($legacy->sent) === 1 && $column('actuator_topic', $legacy->sent[0][1]) === HW_TOPIC, 'Only the recorded hardware proposal may be sent');
$hwBot->reconcileMessages();
ok(count($legacy->edits) === 1 && $legacy->edits[0][0] === 77, 'Legacy message buttons were not removed');
ok(str_contains($legacy->edits[0][1], 'Actuator: not recorded (legacy proposal)') && !str_contains($legacy->edits[0][1], HW_TOPIC) && !str_contains($legacy->edits[0][1], SIM_TOPIC), 'Legacy message invented an actuator topic');

echo "PASS proposal trigger, duplicate/stale/gap/recovery, reminder/expiry, authorization, replay, concurrent approval, 24-hour boundary, uncertain delivery, restart, polling offset and actuator switching\n";
$kernel->shutdown();
