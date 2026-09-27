<?php

declare(strict_types=1);

namespace App\Watering;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

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

        $client = new MqttClient($this->host, $this->port, 'gardenhub-watering-publish-'.bin2hex(random_bytes(5)));
        try {
            $client->connect((new ConnectionSettings())->setUsername($this->username)->setPassword($this->password), true);
            // Never retain actuator commands. Wait for PUBACK before returning.
            $client->publish(self::TOPIC.'/set', json_encode($command, JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE, false);
            $client->loop(true, true, 5);
        } finally {
            if ($client->isConnected()) {
                $client->disconnect();
            }
        }
    }
}
