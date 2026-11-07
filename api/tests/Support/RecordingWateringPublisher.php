<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Watering\InterfaceWateringPublisher;

final class RecordingWateringPublisher implements InterfaceWateringPublisher
{
    public array $calls = [];
    public bool $fail = false;
    public string $failureMessage = 'Test broker disconnected';
    public function publish(array $command): void
    {
        $this->calls[] = $command;
        if ($this->fail) {
            throw new \RuntimeException($this->failureMessage);
        }
    }
}