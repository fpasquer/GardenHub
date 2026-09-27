<?php

declare(strict_types=1);

use App\Watering\WateringManager;
use App\Watering\WateringPublisher;
use Doctrine\DBAL\DriverManager;

require '/app/vendor/autoload.php';

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

$db = DriverManager::getConnection(['url' => getenv('DATABASE_URL')]);
check($db->getDatabase() === 'watering_control_test', 'Refusing to use a non-test database.');
$publisher = new FakePublisher();
$watering = new WateringManager($db, $publisher, true, 'dev');

rejects(fn () => $watering->request(0), 'Zero duration accepted');
rejects(fn () => $watering->request(31), 'Excessive duration accepted');
rejects(fn () => (new WateringManager($db, $publisher, true, 'prod'))->request(3), 'Production enabled dev control');
rejects(fn () => (new WateringManager($db, $publisher, false, 'dev'))->request(3), 'Disabled control accepted');
rejects(fn () => $watering->request(3), 'Missing monitor heartbeat allowed watering');
$db->executeStatement('UPDATE watering_control SET monitor_seen_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = 1');
rejects(fn () => $watering->request(3), 'Stale monitor heartbeat allowed watering');
$watering->heartbeat();

$id = $watering->request(3);
check(count($publisher->commands) === 1 && $publisher->commands[0] === ['watering_times' => 3, 'state' => 'ON'], 'Expected one non-retained ON command');
rejects(fn () => $watering->request(3), 'Duplicate request accepted');
check(!$watering->observe('{"state":"OFF"}'), 'OFF should not require another stop');
check($watering->latest()['status'] === 'pending', 'Stale retained OFF completed a pending run');
check(!$watering->observe('{"state":"ON","alarm_1":false,"battery_low":false}'), 'Healthy ON required stop');
check($watering->latest()['status'] === 'running', 'ON not recorded');

// Simulate a new PHP process using the same persistent database.
$restarted = new WateringManager($db, $publisher, true, 'dev');
rejects(fn () => $restarted->request(3), 'Restart allowed duplicate watering');
$restarted->observe('{"state":"OFF"}');
check($restarted->latest()['status'] === 'completed', 'OFF after ON did not complete the run');
rejects(fn () => $restarted->request(3), 'Cooldown not enforced');

// Advance only the stored timestamp in the isolated test database.
$db->executeStatement('UPDATE watering_control SET last_request_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id = 1');
$publisher->fail = true;
rejects(fn () => $watering->request(3), 'Broker failure was reported as a successful request');
check($watering->latest()['status'] === 'uncertain' && $watering->requiresStop(), 'Uncertain publish did not block future watering');
rejects(fn () => $watering->request(3), 'Uncertain cycle allowed another request');

// A timeout still blocks after OFF: without an observed ON, a retained OFF
// cannot establish that the device ever ran or stopped in this cycle.
$db->executeStatement("UPDATE watering_run SET status = 'pending', deadline_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = (SELECT active_run_id FROM watering_control WHERE id = 1)");
check($restarted->expire(), 'Expired request was not detected');
check($restarted->latest()['status'] === 'timed_out', 'Timeout not persisted');
$restarted->observe('{"state":"OFF"}');
check($restarted->requiresStop(), 'Retained OFF incorrectly unblocked a timeout');
rejects(fn () => $restarted->request(3), 'Timeout allowed another request');
$restarted->acknowledgeStopped();
check($restarted->latest()['status'] === 'reviewed' && !$restarted->requiresStop(), 'Manual review did not release the blocked cycle');

// Reset the isolated database for fault and budget scenarios.
$db->executeStatement('DELETE FROM watering_run');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL WHERE id = 1');
$publisher->fail = false;
$watering->request(3);
check($watering->observe('{"state":"ON","alarm_1":true}'), 'Water shortage did not request OFF');
check($watering->latest()['status'] === 'uncertain', 'Water shortage did not block cycle');

$db->executeStatement('DELETE FROM watering_run');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL WHERE id = 1');
$watering->request(3);
check($watering->observe('{"state":"ON","battery_low":true}'), 'Low battery did not request OFF');

$db->executeStatement('DELETE FROM watering_run');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL WHERE id = 1');
$db->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES ('00000000-0000-4000-8000-000000000001', 119, 'completed', NOW(), NOW())");
rejects(fn () => $watering->request(2), 'Rolling 24-hour budget exceeded');

echo "PASS dev watering: duration, duplicate, restart, cooldown, MQTT failure, timeout, water shortage, low battery and daily budget\n";
