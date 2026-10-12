<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Watering\MqttWateringPublisher;
use App\Watering\ProposalBot;
use App\Watering\ProposalPolicy;
use App\Watering\WateringManager;
use Symfony\Component\Uid\Uuid;

final class ProposalFixture
{
    public const SIM_TOPIC = MqttWateringPublisher::DEFAULT_TOPIC;
    public const HW_TOPIC = 'zigbee2mqtt/avocado-watering';
    public readonly WateringHarness $harness;
    public readonly RecordingWateringPublisher $publisher;
    public readonly RecordingTelegramGateway $telegram;
    public readonly WateringManager $watering;
    public readonly ProposalPolicy $policy;
    public readonly ProposalBot $bot;
    public readonly int $device;
    public readonly int $sensor;

    public function __construct()
    {
        $this->harness = new WateringHarness();
        $db = $this->harness->connection;
        $db->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
        $db->executeStatement('UPDATE watering_telegram_progress SET next_update_id = 0 WHERE id = 1');
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('device', ['name' => 'SE01-Avocado', 'created_at' => $now]);
        $this->device = (int) $db->lastInsertId();
        $db->insert('sensor', ['device_id' => $this->device, 'type' => 'soil_moisture',
            'unit' => '%', 'created_at' => $now]);
        $this->sensor = (int) $db->lastInsertId();
        $this->publisher = new RecordingWateringPublisher();
        $this->telegram = new RecordingTelegramGateway();
        $this->watering = \newTestManager($this->harness->manager, $this->publisher);
        $this->policy = \newTestPolicy($this->harness->manager, $this->watering, self::SIM_TOPIC);
        $this->bot = \newTestBot($this->harness->manager, $this->policy, $this->telegram);
    }

    public function insert(float $value, int $ageMinutes): void
    {
        $this->harness->connection->insert('measurement', ['sensor_id' => $this->sensor,
            'value' => $value, 'type' => 'soil_moisture', 'deduplication_id' => (string) Uuid::v4(),
            'measured_at' => gmdate('Y-m-d H:i:s', time() - $ageMinutes * 60),
            'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function readings(array $ages = [40, 20, 0]): void
    {
        $this->harness->connection->executeStatement('DELETE FROM measurement WHERE sensor_id = ?', [$this->sensor]);
        foreach ($ages as $age) {
            $this->insert(14, $age);
        }
    }

    public function reset(): void
    {
        $db = $this->harness->connection;
        $db->executeStatement('DELETE FROM watering_proposal');
        \clearWateringRuns($db);
        $db->executeStatement('DELETE FROM watering_proposal_state');
        $db->executeStatement('UPDATE watering_control SET monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
        $this->publisher->fail = false;
        $this->publisher->calls = [];
        $this->harness->manager->clear();
        $this->readings();
    }

    public function stack(string $topic, RecordingTelegramGateway $gateway): array
    {
        $manager = $this->harness->manager;
        $policy = \newTestPolicy($manager, \newTestManager($manager, $this->publisher), $topic);
        return [$policy, \newTestBot($manager, $policy, $gateway)];
    }

    public function column(string $name, int $id): mixed
    {
        $column = $this->harness->connection->quoteIdentifier($name);
        return $this->harness->connection->fetchOne('SELECT '.$column.' FROM watering_proposal WHERE id = ?', [$id]);
    }

    public function runCount(): int
    {
        return (int) $this->harness->connection->fetchOne('SELECT COUNT(*) FROM watering_run');
    }

    public function nextEpisode(): void
    {
        $db = $this->harness->connection;
        $db->executeStatement('UPDATE watering_run SET requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR)');
        $db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL, monitor_seen_at = UTC_TIMESTAMP() WHERE id = 1');
        $this->insert(18, 0);
        $this->policy->evaluate();
        $this->readings();
    }

    public static function callback(int $updateId, int $proposalId, int $user = 123,
        int $chat = 456, string $type = 'private', string $action = 'a'): array
    {
        return ['update_id' => $updateId, 'callback_query' => [
            'id' => 'cb'.$updateId, 'from' => ['id' => $user],
            'message' => ['chat' => ['id' => $chat, 'type' => $type]],
            'data' => sprintf('w:%s:%d', $action, $proposalId)]];
    }
}