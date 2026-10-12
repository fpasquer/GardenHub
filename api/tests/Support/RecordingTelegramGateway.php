<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Watering\InterfaceTelegramGateway;

final class RecordingTelegramGateway implements InterfaceTelegramGateway
{
    public array $acks = [];
    public array $queue = [];
    public array $sent = [];
    public array $edits = [];
    public int $polls = 0;
    public bool $failSend = false;

    public function send(string $text, int $proposalId): int
    {
        $this->sent[] = [$text, $proposalId];
        if ($this->failSend) {
            throw new \RuntimeException('Delivery outcome uncertain.');
        }
        return count($this->sent);
    }

    public function updates(int $offset): array
    {
        ++$this->polls;
        return array_values(array_filter($this->queue,
            static fn (array $update): bool => $update['update_id'] >= $offset));
    }

    public function acknowledge(string $callbackId): void
    {
        $this->acks[] = $callbackId;
    }

    public function edit(int $messageId, string $text): void
    {
        $this->edits[] = [$messageId, $text];
    }
}