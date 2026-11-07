<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class ProcessEnvironment
{
    private array $environment;
    private array $server;
    private array $process = [];

    public function __construct(array $names)
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        foreach ($names as $name) {
            $this->process[$name] = getenv($name);
        }
    }

    public function restore(): void
    {
        foreach ($this->process as $name => $value) {
            putenv(false === $value ? $name : sprintf('%s=%s', $name, $value));
        }
        $_ENV = $this->environment;
        $_SERVER = $this->server;
    }
}