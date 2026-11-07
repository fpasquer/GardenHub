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

    private const PUBACK_TIMEOUT_SECONDS = 5;
    private const CLIENT_ID_RANDOM_BYTES = 5;

    public function __construct(
        private readonly WateringConfiguration $configuration,
    ) {
    }

    public function topic(): string
    {
        return $this->configuration->topic;
    }

    public function publish(array $command): void
    {
        $this->configuration->assertAvailable();

        // Injected so we can verify the PUBACK actually arrived, not just that loop() returned.
        $repository = new MemoryRepository();
        $client = new MqttClient($this->configuration->host, $this->configuration->port, 'gardenhub-watering-publish-'.bin2hex(random_bytes(self::CLIENT_ID_RANDOM_BYTES)), repository: $repository);
        try {
            $client->connect((new ConnectionSettings())->setUsername($this->configuration->username)->setPassword($this->configuration->password), true);
            // Never retain actuator commands. Wait for PUBACK before returning.
            $client->publish($this->configuration->topic.'/set', json_encode($command, JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE, false);
            $client->loop(true, true, self::PUBACK_TIMEOUT_SECONDS);
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
