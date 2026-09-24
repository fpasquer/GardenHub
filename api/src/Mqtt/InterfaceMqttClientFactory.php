<?php

declare(strict_types=1);

namespace App\Mqtt;

use PhpMqtt\Client\Contracts\MqttClient;

/**
 * Creates connected MQTT clients for the uplink worker.
 *
 * Extracted so tests can inject a scripted fake client/factory pair without
 * a real broker, socket, or sleep.
 */
interface InterfaceMqttClientFactory
{
    public function create(string $clientId, string $topic, callable $callback): MqttClient;
}
