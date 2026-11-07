<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

final class Command
{
    public static function run(
        array $command,
        ?string $directory = null,
        ?array $environment = null,
        ?string $input = null,
        float $timeout = 30,
    ): array {
        $process = new Process($command, $directory, $environment, $input);
        $process->setTimeout($timeout);
        $output = ['stdout' => '', 'stderr' => ''];
        $status = $process->run(static function (
            string $type, string $chunk,
        ) use (&$output, $process): void {
            $key = Process::OUT === $type ? 'stdout' : 'stderr';
            $output[$key] = substr($output[$key].$chunk, -65_536);
            $process->clearOutput();
            $process->clearErrorOutput();
        });

        return ['status' => $status, ...$output];
    }

    public static function root(): string
    {
        $root = getenv('GARDENHUB_ROOT');

        return false === $root ? dirname(__DIR__, 3) : $root;
    }
}