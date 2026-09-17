<?php

declare(strict_types=1);

namespace App\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;

/**
 * Creates connected and subscribed MQTT clients for the uplink worker.
 *
 * The local subscription is registered in the supplied repository BEFORE
 * connect()/subscribe(). In php-mqtt/client, a subscription passed to
 * subscribe() only becomes an active local subscription once the broker's
 * SUBACK arrives. However, when reconnecting to an existing persistent
 * session, the broker may replay QoS 1 messages queued while the worker was
 * offline immediately after CONNACK — before it can receive the new
 * SUBSCRIBE. Such early PUBLISH packets would be acknowledged (PUBACK) but
 * silently dropped without a matching local subscription. Pre-registration
 * closes that gap.
 *
 * Lifecycle notes (php-mqtt/client v2.3.2):
 *  - connect() with a persistent session does not reset the repository, so
 *    the pre-registered subscription survives the connect.
 *  - When SUBACK arrives, addSubscription() replaces any existing entry for
 *    the same topic filter, so the pre-registered subscription is replaced —
 *    never duplicated. Each PUBLISH invokes the callback exactly once.
 */
final class WorkerMqttClientFactory
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    /**
     * Creates a connected client, subscribed with QoS 1 and a persistent session.
     */
    public function create(string $clientId, string $topic, callable $callback): MqttClient
    {
        $settings = (new ConnectionSettings())
            ->setUsername('' !== $this->username ? $this->username : null)
            ->setPassword('' !== $this->password ? $this->password : null)
            ->setKeepAliveInterval(60);

        $repository = new MemoryRepository();
        // Pre-register the subscription: QoS 1 messages replayed by the broker
        // before our SUBACK still match this local subscription. On SUBACK,
        // the client replaces this entry for the same topic filter.
        $repository->addSubscription(new Subscription($topic, MqttClient::QOS_AT_LEAST_ONCE, $callback));

        $client = new MqttClient($this->host, $this->port, $clientId, MqttClient::MQTT_3_1, $repository);
        $client->connect($settings, false);
        $client->subscribe($topic, $callback, MqttClient::QOS_AT_LEAST_ONCE);

        return $client;
    }
}
