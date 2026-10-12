<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

final class WateringBrokerProbe
{
    public readonly int $port;
    private Process $process;

    public function __construct(string $mode)
    {
        $this->process = new Process([PHP_BINARY, __DIR__.'/watering_broker.php', $mode], timeout: 25);
        $this->process->start();
        $deadline = microtime(true) + 10;
        while (!str_contains($this->process->getOutput(), "\n")) {
            Assert::assertTrue($this->process->isRunning(), $this->process->getErrorOutput());
            Assert::assertLessThan($deadline, microtime(true), 'Broker readiness deadline.');
            usleep(10000);
        }
        $ready = json_decode(explode("\n", $this->process->getOutput())[0], true, 512, JSON_THROW_ON_ERROR);
        $this->port = $ready['port'];
    }

    public function finish(): array
    {
        try {
            $this->process->wait();
            Assert::assertSame(0, $this->process->getExitCode(), substr($this->process->getErrorOutput(), -65536));
            $lines = explode("\n", trim($this->process->getOutput()));
            return json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->process->stop();
        }
    }

    public function __destruct()
    {
        $this->process->stop();
    }
}