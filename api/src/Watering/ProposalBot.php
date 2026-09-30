<?php

declare(strict_types=1);

namespace App\Watering;

use App\Entity\WateringProposal;
use App\Repository\WateringTelegramProgressRepository;

final class ProposalBot
{
    public function __construct(
        private readonly WateringTelegramProgressRepository $progress,
        private readonly ProposalPolicy $policy,
        private readonly TelegramGateway $telegram,
        private readonly string $expectedUserId,
        private readonly string $expectedChatId,
    ) {
    }

    public function evaluateAndNotify(): void
    {
        $this->policy->evaluate();
        foreach ($this->policy->newNotifications() as $proposal) {
            $proposalId = (int) $proposal->getId();
            if (!$this->policy->claimNotification($proposalId)) {
                continue;
            }
            try {
                $id = $this->telegram->send($this->text($proposal), $proposalId);
                $this->policy->recordMessage($proposalId, $id);
            } catch (\Throwable) {
                // Send may have succeeded before a timeout/crash. Never resend this episode.
                $this->policy->notificationUncertain($proposalId);
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
        if ((string) $from === $this->expectedUserId && (string) ($chat['id'] ?? '') === $this->expectedChatId && ($chat['type'] ?? null) === 'private' && is_string($data) && preg_match('/^w:([ar]):([0-9]{1,10})$/D', $data, $m)) {
            $this->policy->decide((int) $m[2], $m[1] === 'a' ? 'approve' : 'reject');
        }
        // Even rejected and replayed callbacks are answered; a failed answer replays safely.
        $this->telegram->acknowledge($callback['id']);
    }

    /** Offset moves only after each callback has reached a durable decision and been acknowledged. */
    public function pollOnce(): void
    {
        $offset = $this->progress->getOffset();
        foreach ($this->telegram->updates($offset) as $update) {
            $id = $update['update_id'] ?? null;
            if (!is_int($id) || $id < $offset) {
                continue;
            }
            $this->process($update);
            $offset = $id + 1;
            $this->progress->advanceTo($offset);
        }
    }

    public function reconcileMessages(): void
    {
        foreach ($this->policy->finalMessages() as $p) {
            try {
                $this->telegram->edit((int) $p->getMessageId(), $this->finalText($p));
                $this->policy->markMessageFinal((int) $p->getId());
            } catch (\Throwable) {
                // Persisted decision stands. A later iteration retries the display update.
            }
        }
    }

    private function finalText(WateringProposal $p): string
    {
        $runId = $p->getRun()?->getId();
        $failure = $p->getFailure();

        return sprintf(
            "%s\nDecision: %s%s%s",
            $this->text($p),
            $p->getStatus(),
            $runId ? sprintf(' (run %d)', $runId) : '',
            $failure ? sprintf(' — %s', $failure) : '',
        );
    }

    private function text(WateringProposal $p): string
    {
        $lines = [
            'GardenHub dev watering proposal',
            sprintf('Device: %s', $p->getDevice()?->getName()),
            sprintf('Actuator: %s', $this->actuatorLabel($p->getActuatorTopic())),
        ];
        foreach ($p->getReadingsJson() as $r) {
            $lines[] = sprintf('%s UTC: %s%%', $r['measured_at'], $r['value']);
        }
        $attempt = $p->getLastAttemptAt();
        $lines[] = sprintf('Last watering attempt: %s', $attempt ? $attempt->format('Y-m-d H:i:s').' UTC' : 'none');
        $lines[] = sprintf('Proposed watering duration: %d seconds', $p->getDurationSeconds());
        $lines[] = sprintf('Expires: %s UTC', $p->getExpiresAt()?->format('Y-m-d H:i:s'));

        return implode("\n", $lines);
    }

    /** Legacy proposals have no recorded topic; never guess one. */
    private function actuatorLabel(?string $topic): string
    {
        if ($topic === null) {
            return 'not recorded (legacy proposal)';
        }
        $kind = str_starts_with($topic, 'zigbee2mqtt/') ? 'physical pump' : 'configured MQTT actuator';

        return sprintf('%s (%s)', $kind, $topic);
    }
}
