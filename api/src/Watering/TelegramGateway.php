<?php

declare(strict_types=1);

namespace App\Watering;

interface TelegramGateway
{
    public function send(string $text, int $proposalId): int;
    public function updates(int $offset): array;
    public function acknowledge(string $callbackId): void;
    public function edit(int $messageId, string $text): void;
}
