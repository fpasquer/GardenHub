<?php

declare(strict_types=1);

// Mapping guarantees of the watering entities: integer-only JSON, fresh reads
// after bulk updates, foreign-key protection and Telegram id bounds.

use App\Entity\WateringProposal;
use App\Kernel;
use App\Repository\WateringProposalRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

function check(bool $yes, string $message): void
{
    if (!$yes) {
        throw new RuntimeException($message);
    }
}

function throwsForeignKey(callable $statement): bool
{
    try {
        $statement();
    } catch (ForeignKeyConstraintViolationException) {
        return true;
    }

    return false;
}

$kernel = new Kernel('dev', true);
$em = bootTestEntityManager($kernel);
$db = $em->getConnection();
$db->executeStatement('DELETE FROM watering_proposal');
clearWateringRuns($db);
$db->executeStatement('DELETE FROM device WHERE name = ?', ['orm-mapping']);
$now = gmdate('Y-m-d H:i:s');
$db->insert('device', ['name' => 'orm-mapping', 'created_at' => $now]);
$deviceId = (int) $db->lastInsertId();
$db->insert('watering_run', ['requested_seconds' => 3, 'status' => 'requested', 'requested_at' => $now, 'deadline_at' => $now]);
$runId = (int) $db->lastInsertId();
$insertProposal = static function (?int $run) use ($db, $deviceId, $now): int {
    $db->insert('watering_proposal', [
        'device_id' => $deviceId,
        'status' => 'executing',
        'created_at' => $now,
        'expires_at' => $now,
        'duration_seconds' => 3,
        'readings_json' => '[]',
        'notification_status' => 'sent',
        'run_id' => $run,
    ]);

    return (int) $db->lastInsertId();
};
$proposalId = $insertProposal($runId);

// Serialized output exposes identifiers only, never nested entities.
$serializer = testSerializer($kernel);
$proposal = freshProposal($em, $proposalId);
$json = json_decode($serializer->serialize($proposal, 'json', ['groups' => ['read:watering_proposal']]), true, 512, JSON_THROW_ON_ERROR);
check($json['id'] === $proposalId && $json['deviceId'] === $deviceId && $json['runId'] === $runId, 'Proposal ids are not serialized as integers');
check(!array_key_exists('run', $json) && !array_key_exists('device', $json), 'Proposal serialized a nested entity');
$db->executeStatement('UPDATE watering_control SET active_run_id = ? WHERE id = 1', [$runId]);
$json = json_decode($serializer->serialize(freshControl($em), 'json', ['groups' => ['read:watering_control']]), true, 512, JSON_THROW_ON_ERROR);
check($json['activeRunId'] === $runId && !array_key_exists('activeRun', $json), 'Control active run is not an integer id');
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL WHERE id = 1');

// A proposal loaded before a bulk update must not be trusted afterwards.
/** @var WateringProposalRepository $proposals */
$proposals = $em->getRepository(WateringProposal::class);
$em->clear();
$stale = $em->find(WateringProposal::class, $proposalId);
check(1 === $proposals->finishExecution($proposalId, WateringProposal::STATUS_FAILED, 'boom', null), 'Compare-and-set did not update one row');
check(WateringProposal::STATUS_EXECUTING === $stale->getStatus(), 'Bulk update unexpectedly synchronized the loaded entity');
$fresh = $proposals->fresh($proposalId);
check(WateringProposal::STATUS_FAILED === $fresh->getStatus() && 'boom' === $fresh->getFailure() && $runId === $fresh->getRunId(), 'fresh() returned stale data');
check(0 === $proposals->finishExecution($proposalId, WateringProposal::STATUS_APPROVED, null, $runId), 'Terminal proposal was updated again');

// Deleting a proposal or clearing the control never deletes the run.
$linked = $insertProposal($runId);
$db->executeStatement('DELETE FROM watering_proposal WHERE id = ?', [$linked]);
$db->executeStatement('UPDATE watering_control SET active_run_id = ? WHERE id = 1', [$runId]);
$db->executeStatement('UPDATE watering_control SET active_run_id = NULL WHERE id = 1');
check(1 === (int) $db->fetchOne('SELECT COUNT(*) FROM watering_run WHERE id = ?', [$runId]), 'A run was removed with its referrer');

// Foreign keys reject orphans in both directions.
check(throwsForeignKey(fn () => $insertProposal(2147483000)), 'Orphan proposal run was accepted');
check(throwsForeignKey(fn () => $db->executeStatement('UPDATE watering_control SET active_run_id = 2147483000 WHERE id = 1')), 'Orphan active run was accepted');
$insertProposal($runId);
check(throwsForeignKey(fn () => $db->executeStatement('DELETE FROM watering_run WHERE id = ?', [$runId])), 'A referenced run was deleted');

// Telegram callbacks outside the integer range, including old UUID buttons, change nothing.
$publisher = new class implements App\Watering\InterfaceWateringPublisher {
    public int $calls = 0;

    public function publish(array $command): void
    {
        ++$this->calls;
    }
};
$telegram = new class implements App\Watering\InterfaceTelegramGateway {
    public array $acks = [];
    public array $queue = [];

    public function send(string $text, int $proposalId): int
    {
        return 1;
    }

    public function updates(int $offset): array
    {
        return $this->queue;
    }

    public function acknowledge(string $callbackId): void
    {
        $this->acks[] = $callbackId;
    }

    public function edit(int $messageId, string $text): void
    {
    }
};
$policy = newTestPolicy($em, newTestManager($em, $publisher), 'test/topic');
$bot = newTestBot($em, $policy, $telegram);
$before = $db->fetchAllAssociative('SELECT id, status FROM watering_proposal ORDER BY id');
$payloads = ['w:a:0', 'w:a:-1', 'w:a:2147483648', 'w:a:99999999999', 'w:a:'.str_repeat('9', 40), 'w:a:018f4c5e-0000-7000-8000-000000000000', 'w:a:', 'w:x:1', 'garbage'];
foreach ($payloads as $i => $data) {
    $bot->process(['update_id' => $i, 'callback_query' => ['id' => 'c'.$i, 'from' => ['id' => 123], 'message' => ['chat' => ['id' => 456, 'type' => 'private']], 'data' => $data]]);
}
check(count($telegram->acks) === count($payloads), 'Malformed callbacks were not acknowledged');
check($before === $db->fetchAllAssociative('SELECT id, status FROM watering_proposal ORDER BY id') && 0 === $publisher->calls, 'A malformed callback changed state');

$db->executeStatement('DELETE FROM watering_proposal');
clearWateringRuns($db);
$db->executeStatement('DELETE FROM device WHERE id = ?', [$deviceId]);
echo "PASS orm mapping: integer JSON, fresh reads, foreign keys and callback bounds\n";
