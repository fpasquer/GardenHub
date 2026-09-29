<?php

declare(strict_types=1);

namespace App\Watering;

use PhpMqtt\Client\Contracts\MqttClient;
use PhpMqtt\Client\Contracts\Repository;

/** Confirms a SUBACK was actually received; a rejected (QoS 128) subscription never appears in the repository, so it times out the same way. */
final class SubscriptionReadyGate
{
    public static function await(MqttClient $client, Repository $repository, float $timeoutSeconds, ?\Closure $afterLoop = null): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $loopStartedAt = microtime(true);
        while ($repository->countSubscriptions() === 0) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Subscription acknowledgement (SUBACK) not received within timeout.');
            }
            $client->loopOnce($loopStartedAt, true);
            $afterLoop?->__invoke();
        }
    }
}
