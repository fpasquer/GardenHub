<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

use App\Mqtt\InterfaceMqttClientFactory;
use PhpMqtt\Client\Contracts\MqttClient;

/**
 * Scripted App\Mqtt\InterfaceMqttClientFactory test double: each create()
 * call consumes the next script entry, either a \Throwable (thrown directly,
 * simulating a connect failure) or a \Closure (becomes the returned client's
 * loop() body). Repeats the last entry once the script is exhausted.
 *
 * @param list<\Throwable|\Closure> $script
 */
final class ScriptedMqttClientFactory implements InterfaceMqttClientFactory
{
    private int $callCount = 0;

    public function __construct(private readonly array $script)
    {
    }

    public function create(string $clientId, string $topic, callable $callback): MqttClient
    {
        $index = min($this->callCount, count($this->script) - 1);
        ++$this->callCount;
        $entry = $this->script[$index];

        if ($entry instanceof \Throwable) {
            throw $entry;
        }

        return new ScriptedMqttClient($entry);
    }
}
