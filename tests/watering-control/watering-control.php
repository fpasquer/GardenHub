<?php

declare(strict_types=1);

use App\Watering\WateringPublisher;
use App\Entity\WateringRun;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

final class FakePublisher implements WateringPublisher
{
    public array $commands = [];
    public bool $fail = false;

    public function publish(array $command): void
    {
        $this->commands[] = $command;
        if ($this->fail) {
            throw new RuntimeException('Test broker disconnected');
        }
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException|LogicException|RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

function runById(EntityManagerInterface $em, int $id): WateringRun
{
    $run = freshRun($em, $id);
    check($run instanceof WateringRun, 'Run not found by id: '.$id);
    return $run;
}

function activeRunId(EntityManagerInterface $em): ?int
{
    return freshControl($em)->getActiveRun()?->getId();
}

$kernel = new Kernel('dev', true);
$em = bootTestEntityManager($kernel);
$db = $em->getConnection();
$publisher = new FakePublisher();
$watering = newTestManager($em, $publisher);

rejects(fn () => $watering->request(0), 'Zero duration accepted');
rejects(fn () => $watering->request(31), 'Excessive duration accepted');
rejects(fn () => (newTestManager($em, $publisher, true, 'prod'))->request(3), 'Production enabled dev control');
rejects(fn () => (newTestManager($em, $publisher, false, 'dev'))->request(3), 'Disabled control accepted');
rejects(fn () => $watering->request(3), 'Missing monitor heartbeat allowed watering');
$db->executeStatement('UPDATE watering_control SET monitor_seen_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = 1');
rejects(fn () => $watering->request(3), 'Stale monitor heartbeat allowed watering');
$watering->heartbeat();

$id = $watering->request(3);
check(count($publisher->commands) === 1 && $publisher->commands[0] === ['watering_times' => 3, 'state' => 'ON'], 'Expected one non-retained ON command');
rejects(fn () => $watering->request(3), 'Duplicate request accepted');
check(!$watering->observe('{"state":"OFF"}'), 'OFF should not require another stop');
check(runById($em, $id)->getStatus() === 'pending', 'Stale retained OFF completed a pending run');
check(!$watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}'), 'Healthy ON required stop');
check(runById($em, $id)->getStatus() === 'running', 'ON not recorded');

// Simulate a new PHP process using the same persistent database.
$restarted = newTestManager($em, $publisher);
rejects(fn () => $restarted->request(3), 'Restart allowed duplicate watering');
$restarted->observe('{"state":"OFF"}');
check(runById($em, $id)->getStatus() === 'completed', 'OFF after ON did not complete the run');
rejects(fn () => $restarted->request(3), 'Cooldown not enforced');

// Advance only the stored timestamp in the isolated test database.
$db->executeStatement('UPDATE watering_control SET last_request_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id = 1');
$publisher->fail = true;
rejects(fn () => $watering->request(3), 'Broker failure was reported as a successful request');
// request() retains the reservation on publish failure, so active_run_id still points at it.
$uncertainId = activeRunId($em);
check($uncertainId !== null, 'Failed publish did not retain a reservation');
check(runById($em, $uncertainId)->getStatus() === 'uncertain' && $watering->requiresStop(), 'Uncertain publish did not block future watering');
rejects(fn () => $watering->request(3), 'Uncertain cycle allowed another request');

// A timeout still blocks after OFF: without an observed ON, a retained OFF
// cannot establish that the device ever ran or stopped in this cycle.
$db->executeStatement("UPDATE watering_run SET status = 'pending', deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?", [$uncertainId]);
check($restarted->expire(), 'Expired request was not detected');
check(runById($em, $uncertainId)->getStatus() === 'timed_out', 'Timeout not persisted');
$restarted->observe('{"state":"OFF"}');
check($restarted->requiresStop(), 'Retained OFF incorrectly unblocked a timeout');
rejects(fn () => $restarted->request(3), 'Timeout allowed another request');
$restarted->acknowledgeStopped();
check(runById($em, $uncertainId)->getStatus() === 'reviewed' && !$restarted->requiresStop(), 'Manual review did not release the blocked cycle');

// Reset the isolated database for fault and budget scenarios.
clearWateringRuns($db);
$publisher->fail = false;
$shortageId = $watering->request(3);
check($watering->observe('{"state":"ON","alarm_1":true}'), 'Water shortage did not request OFF');
check(runById($em, $shortageId)->getStatus() === 'uncertain', 'Water shortage did not block cycle');

clearWateringRuns($db);
$batteryId = $watering->request(3);
check($watering->observe('{"state":"OFF","battery_low":true}'), 'Low battery on an OFF report did not request OFF');
check(runById($em, $batteryId)->getStatus() === 'uncertain', 'Low battery did not block the cycle');

clearWateringRuns($db);
$db->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (1, 119, 'completed', NOW(), NOW())");
rejects(fn () => $watering->request(2), 'Rolling 24-hour budget exceeded');

// An overdue *pending* cycle must become timed_out on the very next report,
// even a plain OFF, instead of silently vanishing before expire() runs.
clearWateringRuns($db);
$overduePendingId = $watering->request(3);
$db->executeStatement('UPDATE watering_run SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$overduePendingId]);
check($watering->observe('{"state":"OFF"}'), 'Overdue pending run receiving OFF did not request a stop');
check(runById($em, $overduePendingId)->getStatus() === 'timed_out', 'Overdue pending run was not marked timed_out before expire() ran');
check($watering->requiresStop(), 'Overdue pending run did not remain blocked');
check(activeRunId($em) === $overduePendingId, 'Overdue pending run lost its reservation');

// Same rule for an overdue *running* cycle: OFF must not be mistaken for a
// normal, on-time completion.
clearWateringRuns($db);
$overdueRunningId = $watering->request(3);
$watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}');
check(runById($em, $overdueRunningId)->getStatus() === 'running', 'Fixture did not reach running before the deadline check');
$db->executeStatement('UPDATE watering_run SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$overdueRunningId]);
check($watering->observe('{"state":"OFF"}'), 'Overdue running run receiving OFF did not request a stop');
check(runById($em, $overdueRunningId)->getStatus() === 'timed_out', 'Overdue running run was completed instead of timed_out');
check($watering->requiresStop(), 'Overdue running run did not remain blocked');
check(activeRunId($em) === $overdueRunningId, 'Overdue running run lost its reservation');

// Once a cycle is blocked, later reports (even a deadline further in the
// past) must never re-touch its status, error or reservation.
clearWateringRuns($db);
$blockedId = $watering->request(3);
check($watering->observe('{"state":"ON","alarm_1":true}'), 'Fixture did not block the run as expected');
$db->executeStatement('UPDATE watering_run SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [$blockedId]);
$watering->observe('{"state":"OFF"}');
$watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}');
$blockedRun = runById($em, $blockedId);
check($blockedRun->getStatus() === 'uncertain', 'Already-blocked run status changed by a later report');
check($blockedRun->getError() === 'Water shortage or low battery reported', 'Already-blocked run error text changed by a later report');
check($watering->requiresStop(), 'Already-blocked run stopped requiring a stop');
check(activeRunId($em) === $blockedId, 'Already-blocked run lost its reservation');

// requiresStop() must inspect active_run_id directly: two runs sharing the
// same requested_at, with the *other* run's id sorting after the active
// one's, would flip latest()'s tie-break to the wrong row.
clearWateringRuns($db);
$tieActiveId = 1;
$tieOtherId = 2;
$tieTimestamp = gmdate('Y-m-d H:i:s');
$db->executeStatement(
    "INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at, error) VALUES (?, 3, 'uncertain', ?, ?, 'tie-break fixture')",
    [$tieActiveId, $tieTimestamp, $tieTimestamp],
);
$db->executeStatement(
    "INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (?, 3, 'completed', ?, ?)",
    [$tieOtherId, $tieTimestamp, $tieTimestamp],
);
$db->executeStatement('UPDATE watering_control SET active_run_id = ? WHERE id = 1', [$tieActiveId]);
check($watering->latest()?->getId() === $tieOtherId, 'Fixture no longer reproduces the requested_at tie this regression targets');
check($watering->requiresStop(), 'requiresStop() followed the tie-broken latest() run instead of active_run_id');

echo "PASS dev watering: duration, duplicate, restart, cooldown, MQTT failure, timeout, overdue pending/running, blocked-run isolation, tie-break, water shortage, low battery and daily budget\n";
$kernel->shutdown();
