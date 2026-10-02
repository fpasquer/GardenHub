<?php

declare(strict_types=1);

namespace App\Watering;

final readonly class WateringConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $environment,
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $topic,
    ) {
    }

    public function assertAvailable(): void
    {
        if (!$this->enabled || !in_array($this->environment, ['dev', 'prod'], true)) {
            throw new \LogicException('Watering requires explicit enablement in dev or prod.');
        }
        if (!$this->isConfigured()) {
            throw new \LogicException('Watering requires MQTT host, port, credentials and a concrete base topic.');
        }
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->host)
            && $this->port >= 1 && $this->port <= 65535
            && '' !== trim($this->username) && '' !== trim($this->password)
            && '' !== trim($this->topic)
            && $this->topic === trim($this->topic)
            && !str_contains($this->topic, '#')
            && !str_contains($this->topic, '+')
            && !str_contains($this->topic, "\0")
            && !str_ends_with($this->topic, '/set');
    }
}