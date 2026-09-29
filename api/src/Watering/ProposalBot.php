<?php

declare(strict_types=1);

namespace App\Watering;

use Doctrine\DBAL\Connection;

final class ProposalBot
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProposalPolicy $policy,
        private readonly TelegramGateway $telegram,
        private readonly string $expectedUserId,
        private readonly string $expectedChatId,
        private readonly string $actuatorTopic = MqttWateringPublisher::DEFAULT_TOPIC,
    ) {
    }

    public function evaluateAndNotify(): void
    {
        $this->policy->evaluate();
        foreach ($this->policy->newNotifications() as $proposal) {
            if (!$this->policy->claimNotification($proposal['id'])) {
                continue;
            }
            try {
                $id = $this->telegram->send($this->text($proposal), $proposal['id']);
                $this->policy->recordMessage($proposal['id'], $id);
            } catch (\Throwable) {
                // Send may have succeeded before a timeout/crash. Never resend this episode.
                $this->policy->notificationUncertain($proposal['id']);
            }
        }
    }

    public function process(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        if (!is_array($callback) || !is_string($callback['id'] ?? null)) {
            return;
        }
        $from = $callback['from']['id'] ?? null;
        $chat = $callback['message']['chat'] ?? null;
        $data = $callback['data'] ?? null;
        if ((string) $from === $this->expectedUserId && (string) ($chat['id'] ?? '') === $this->expectedChatId && ($chat['type'] ?? null) === 'private' && is_string($data) && preg_match('/^w:([ar]):([0-9a-f-]{36})$/D', $data, $m)) {
            $this->policy->decide($m[2], $m[1] === 'a' ? 'approve' : 'reject');
        }
        // Even rejected and replayed callbacks are answered; a failed answer replays safely.
        $this->telegram->acknowledge($callback['id']);
    }

    /** Offset moves only after each callback has reached a durable decision and been acknowledged. */
    public function pollOnce(): void
    {
        $offset = (int) $this->db->fetchOne('SELECT next_update_id FROM watering_telegram_progress WHERE id = 1');
        foreach ($this->telegram->updates($offset) as $update) {
            $id = $update['update_id'] ?? null;
            if (!is_int($id) || $id < $offset) {
                continue;
            }
            $this->process($update);
            $offset = $id + 1;
            $this->db->executeStatement('UPDATE watering_telegram_progress SET next_update_id = ? WHERE id = 1 AND next_update_id < ?', [$offset, $offset]);
        }
    }

    public function reconcileMessages(): void
    {
        foreach ($this->policy->finalMessages() as $p) {
            try {
                $this->telegram->edit((int) $p['message_id'], $this->text($p)."\nDecision: ".$p['status'].($p['run_id'] ? ' (run '.$p['run_id'].')' : '').($p['failure'] ? ' — '.$p['failure'] : ''));
                $this->policy->markMessageFinal($p['id']);
            } catch (\Throwable) {
                // Persisted decision stands. A later iteration retries the display update.
            }
        }
    }

    private function text(array $p): string
    {
        $actuator = str_starts_with($this->actuatorTopic, 'zigbee2mqtt/') ? 'physical pump' : 'configured MQTT actuator';
        $lines = ['GardenHub dev watering proposal', 'Device: '.$p['device_name'], 'Actuator: '.$actuator.' ('.$this->actuatorTopic.')'];
        foreach (json_decode($p['readings_json'], true, 512, JSON_THROW_ON_ERROR) as $r) {
            $lines[] = $r['measured_at'].' UTC: '.$r['value'].'%';
        }
        $lines[] = 'Last watering attempt: '.($p['last_attempt_at'] ? $p['last_attempt_at'].' UTC' : 'none');
        $lines[] = 'Proposed watering duration: '.$p['duration_seconds'].' seconds';
        $lines[] = 'Expires: '.$p['expires_at'].' UTC';
        return implode("\n", $lines);
    }
}
