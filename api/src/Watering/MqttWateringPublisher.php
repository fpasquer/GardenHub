<?php

declare(strict_types=1);

namespace App\Watering;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

final class MqttWateringPublisher implements WateringPublisher
{
    public const TOPIC = 'gardenhub/dev/watering/avocado';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $environment,
    ) {
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
            $client->publish(self::TOPIC.'/set', json_encode($command, JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE, false);
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
