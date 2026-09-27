<?php

declare(strict_types=1);

namespace App\Watering;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** The database owns the dry episode and decision; no sensor event directly starts watering. */
final class ProposalPolicy
{
    private const DEVICE_NAME = 'SE01-Avocado';

    public function __construct(
        private readonly Connection $db,
        private readonly WateringManager $watering,
        private readonly float $threshold,
        private readonly int $freshnessMinutes,
        private readonly int $maxGapMinutes,
        private readonly int $validityMinutes,
        private readonly int $durationSeconds,
    ) {
        if (!is_finite($threshold) || $threshold <= 0 || $threshold > 100 || $freshnessMinutes < 1 || $maxGapMinutes < 1 || $validityMinutes < 1 || $durationSeconds < 1 || $durationSeconds > WateringManager::MAX_SECONDS) {
            throw new \LogicException('Invalid dev watering proposal configuration.');
        }
    }

    /** Returns a newly persisted proposal, or null. Serializes evaluation by device row. */
    public function evaluate(): ?array
    {
        return $this->db->transactional(function (Connection $db): ?array {
            $device = $db->fetchAssociative('SELECT id, name FROM device WHERE name = ? FOR UPDATE', [self::DEVICE_NAME]);
            if (!$device) {
                return null;
            }
            $deviceId = (int) $device['id'];
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $nowSql = $now->format('Y-m-d H:i:s');
            $this->invalidateOutstanding($db, $deviceId, $nowSql);
            $readings = $this->readings($db, $deviceId);
            if ($readings && (float) $readings[0]['value'] >= $this->threshold) {
                $db->executeStatement('INSERT INTO watering_proposal_state (device_id, prompt_count) VALUES (?, 0) ON DUPLICATE KEY UPDATE prompt_count = 0, last_prompt_at = NULL', [$deviceId]);
                $db->executeStatement("UPDATE watering_proposal SET status = 'invalidated', failure = 'Sensor recovered' WHERE device_id = ? AND status = 'pending'", [$deviceId]);
                return null;
            }
            if (!$this->dryReadings($readings, $now)) {
                return null;
            }
            $attempt = $this->latestAttempt($db);
            if ($attempt !== null && strtotime($attempt.' UTC') > $now->getTimestamp() - 86400) {
                $db->executeStatement("UPDATE watering_proposal SET status = 'invalidated', failure = 'Newer watering attempt' WHERE device_id = ? AND status = 'pending'", [$deviceId]);
                return null;
            }
            $pending = $db->fetchOne("SELECT 1 FROM watering_proposal WHERE device_id = ? AND status = 'pending' LIMIT 1", [$deviceId]);
            if ($pending) {
                return null;
            }
            $db->executeStatement('INSERT IGNORE INTO watering_proposal_state (device_id, prompt_count) VALUES (?, 0)', [$deviceId]);
            $state = $db->fetchAssociative('SELECT * FROM watering_proposal_state WHERE device_id = ? FOR UPDATE', [$deviceId]);
            // One initial prompt and one reminder at least 24 hours later, until a wet reading resets the episode.
            if ((int) $state['prompt_count'] >= 2 || ((int) $state['prompt_count'] > 0 && strtotime($state['last_prompt_at'].' UTC') > $now->getTimestamp() - 86400)) {
                return null;
            }
            $id = (string) Uuid::v4();
            $snapshot = array_reverse(array_map(static fn (array $r): array => ['value' => (float) $r['value'], 'measured_at' => $r['measured_at']], array_slice($readings, 0, 3)));
            $db->insert('watering_proposal', [
                'id' => $id, 'device_id' => $deviceId, 'status' => 'pending', 'created_at' => $nowSql,
                'expires_at' => $now->modify('+'.$this->validityMinutes.' minutes')->format('Y-m-d H:i:s'),
                'duration_seconds' => $this->durationSeconds, 'readings_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'last_attempt_at' => $attempt, 'notification_status' => 'new',
            ]);
            $db->update('watering_proposal_state', ['prompt_count' => (int) $state['prompt_count'] + 1, 'last_prompt_at' => $nowSql], ['device_id' => $deviceId]);
            return $db->fetchAssociative('SELECT p.*, d.name AS device_name FROM watering_proposal p JOIN device d ON d.id = p.device_id WHERE p.id = ?', [$id]);
        });
    }

    public function claimNotification(string $id): bool
    {
        return 1 === $this->db->executeStatement("UPDATE watering_proposal SET notification_status = 'sending' WHERE id = ? AND status = 'pending' AND notification_status = 'new'", [$id]);
    }

    public function recordMessage(string $id, int $messageId): void
    {
        $this->db->executeStatement("UPDATE watering_proposal SET message_id = ?, notification_status = 'sent' WHERE id = ? AND notification_status = 'sending'", [$messageId, $id]);
    }

    public function notificationUncertain(string $id): void
    {
        $this->db->executeStatement("UPDATE watering_proposal SET notification_status = 'uncertain' WHERE id = ? AND notification_status = 'sending'", [$id]);
    }

    /** Called only after exact user and private-chat authorization. */
    public function decide(string $id, string $action): string
    {
        if (!Uuid::isValid($id) || !in_array($action, ['approve', 'reject'], true)) {
            return 'ignored';
        }
        $claimed = $this->db->transactional(function (Connection $db) use ($id, $action): string {
            $p = $db->fetchAssociative('SELECT * FROM watering_proposal WHERE id = ? FOR UPDATE', [$id]);
            if (!$p || $p['status'] !== 'pending') {
                return 'ignored';
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($p['expires_at'] <= $now->format('Y-m-d H:i:s')) {
                $db->update('watering_proposal', ['status' => 'expired', 'decided_at' => $now->format('Y-m-d H:i:s')], ['id' => $id]);
                return 'expired';
            }
            if ($action === 'reject') {
                $db->update('watering_proposal', ['status' => 'rejected', 'decided_at' => $now->format('Y-m-d H:i:s')], ['id' => $id]);
                return 'rejected';
            }
            // A committed claim is deliberately terminal after a crash. Never automatically reissue request().
            $db->update('watering_proposal', ['status' => 'executing', 'decided_at' => $now->format('Y-m-d H:i:s')], ['id' => $id]);
            return 'executing';
        });
        if ($claimed !== 'executing') {
            return $claimed;
        }
        try {
            $duration = (int) $this->db->fetchOne('SELECT duration_seconds FROM watering_proposal WHERE id = ?', [$id]);
            $runId = $this->watering->request($duration, function (Connection $db, \DateTimeImmutable $now) use ($id): void {
                $p = $db->fetchAssociative('SELECT * FROM watering_proposal WHERE id = ?', [$id]);
                if (!$p || $p['status'] !== 'executing' || $p['expires_at'] <= $now->format('Y-m-d H:i:s')) {
                    throw new \DomainException('Proposal expired or changed.');
                }
                $readings = $this->readings($db, (int) $p['device_id']);
                if (!$this->dryReadings($readings, $now)) {
                    throw new \DomainException('Sensor readings no longer support watering.');
                }
                $attempt = $this->latestAttempt($db);
                if ($attempt !== null && ($attempt > $p['created_at'] || strtotime($attempt.' UTC') > $now->getTimestamp() - 86400)) {
                    throw new \DomainException('A newer or recent watering attempt exists.');
                }
            });
            $this->db->update('watering_proposal', ['status' => 'approved', 'run_id' => $runId], ['id' => $id, 'status' => 'executing']);
            return 'approved';
        } catch (\DomainException|\LogicException $e) {
            $this->db->update('watering_proposal', ['status' => 'failed', 'failure' => substr($e->getMessage(), 0, 255)], ['id' => $id, 'status' => 'executing']);
            return 'failed';
        } catch (\Throwable) {
            // Includes DB or MQTT uncertainty. The manager's reservation, if any, remains blocked.
            $this->db->update('watering_proposal', ['status' => 'uncertain', 'failure' => 'Execution outcome uncertain; manual review required'], ['id' => $id, 'status' => 'executing']);
            return 'uncertain';
        }
    }

    public function expire(): void
    {
        $this->db->executeStatement("UPDATE watering_proposal SET status = 'expired' WHERE status = 'pending' AND expires_at <= UTC_TIMESTAMP()");
        $this->db->executeStatement("UPDATE watering_proposal SET status = 'uncertain', failure = 'Interrupted execution; manual review required' WHERE status = 'executing'");
    }

    public function finalMessages(): array
    {
        return $this->db->fetchAllAssociative("SELECT p.*, d.name AS device_name FROM watering_proposal p JOIN device d ON d.id = p.device_id WHERE p.message_id IS NOT NULL AND p.notification_status = 'sent' AND p.status NOT IN ('pending', 'executing') ORDER BY p.created_at");
    }

    public function markMessageFinal(string $id): void
    {
        $this->db->executeStatement("UPDATE watering_proposal SET notification_status = 'final' WHERE id = ? AND notification_status = 'sent'", [$id]);
    }

    private function invalidateOutstanding(Connection $db, int $deviceId, string $now): void
    {
        $db->executeStatement("UPDATE watering_proposal SET status = 'expired' WHERE device_id = ? AND status = 'pending' AND expires_at <= ?", [$deviceId, $now]);
    }

    private function readings(Connection $db, int $deviceId): array
    {
        return $db->fetchAllAssociative("SELECT m.value, m.measured_at, m.id FROM measurement m JOIN sensor s ON s.id = m.sensor_id WHERE s.device_id = ? AND s.type = 'soil_moisture' AND s.unit = '%' ORDER BY m.measured_at DESC, m.id DESC LIMIT 3", [$deviceId]);
    }

    private function dryReadings(array $r, \DateTimeImmutable $now): bool
    {
        if (count($r) !== 3) {
            return false;
        }
        $times = array_map(static fn (array $row): int => strtotime($row['measured_at'].' UTC'), $r);
        if ($times[0] > $now->getTimestamp() || $now->getTimestamp() - $times[0] > $this->freshnessMinutes * 60) {
            return false;
        }
        for ($i = 0; $i < 3; ++$i) {
            $value = (float) $r[$i]['value'];
            if (!is_finite($value) || $value >= $this->threshold || $value < 0 || $value > 100) {
                return false;
            }
            if ($i > 0 && ($times[$i - 1] <= $times[$i] || $times[$i - 1] - $times[$i] > $this->maxGapMinutes * 60)) {
                return false;
            }
        }
        return true;
    }

    private function latestAttempt(Connection $db): ?string
    {
        $v = $db->fetchOne('SELECT requested_at FROM watering_run ORDER BY requested_at DESC, id DESC LIMIT 1');
        return $v === false ? null : (string) $v;
    }
}
