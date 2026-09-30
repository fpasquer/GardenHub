<?php

declare(strict_types=1);

namespace App\Watering;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

final class MqttWateringPublisher implements InterfaceWateringPublisher
{
    public const DEFAULT_TOPIC = 'gardenhub/dev/watering/avocado';
    /** @deprecated Use DEFAULT_TOPIC for defaults or inject WATERING_MQTT_TOPIC. */
    public const TOPIC = self::DEFAULT_TOPIC;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $environment,
        private readonly string $topic = self::DEFAULT_TOPIC,
    ) {
        if ('' === trim($this->topic) || str_contains($this->topic, '#') || str_contains($this->topic, '+') || str_ends_with($this->topic, '/set')) {
            throw new \LogicException('Watering MQTT topic must be a concrete base topic.');
        }
    }

    public function topic(): string
    {
        return $this->topic;
    }

    public function publish(array $command): void
    {
        if ('dev' !== $this->environment || '' === $this->host || '' === $this->username || '' === $this->password) {
            throw new \LogicException('Development watering MQTT credentials are unavailable.');
        }

        // Injected so we can verify the PUBACK actually arrived, not just that loop() returned.
        $repository = new MemoryRepository();
        $client = new MqttClient($this->host, $this->port, 'gardenhub-watering-publish-'.bin2hex(random_bytes(5)), repository: $repository);
        try {
            $client->connect((new ConnectionSettings())->setUsername($this->username)->setPassword($this->password), true);
            // Never retain actuator commands. Wait for PUBACK before returning.
            $client->publish($this->topic.'/set', json_encode($command, JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE, false);
            $client->loop(true, true, 5);
            if ($repository->countPendingOutgoingMessages() > 0) {
                throw new \RuntimeException('Publish acknowledgement (PUBACK) not received within timeout.');
            }
        } finally {
            try {
                if ($client->isConnected()) {
                    $client->disconnect();
                }
            } catch (\Throwable) {
                // A cleanup failure must never mask the publish outcome above.
            }
        }
    }
}
