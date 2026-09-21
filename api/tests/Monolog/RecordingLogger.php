<?php

namespace App\Tests\Monolog;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger: records (level, message) calls, never does I/O.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string}> */
    public array $records = [];

    private bool $shouldThrow = false;

    public function armToThrow(): void
    {
        $this->shouldThrow = true;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException('Simulated fallback logger failure.');
        }

        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
