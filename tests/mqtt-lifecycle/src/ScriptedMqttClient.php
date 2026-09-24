<?php

declare(strict_types=1);

namespace Tests\MqttLifecycle;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\MqttClient;

/**
 * In-memory MqttClient test double: only loop()/interrupt() are meaningful
 * (the only two methods MqttConsumeCommand calls); everything else is a
 * trivial no-op stub.
 */
final class ScriptedMqttClient implements MqttClient
{
    public function __construct(private readonly \Closure $onLoop)
    {
    }

    public function loop(bool $allowSleep = true, bool $exitWhenQueuesEmpty = false, ?int $queueWaitLimit = null): void
    {
        ($this->onLoop)();
    }

    public function connect(?ConnectionSettings $settings = null, bool $useCleanSession = false): void
    {
    }

    public function disconnect(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
    }

    public function subscribe(string $topicFilter, ?callable $callback = null, int $qualityOfService = 0): void
    {
    }

    public function unsubscribe(string $topicFilter): void
    {
    }

    public function interrupt(): void
    {
    }

    public function loopOnce(float $loopStartedAt, bool $allowSleep = false, int $sleepMicroseconds = 100000): void
    {
    }

    public function getHost(): string
    {
        return 'scripted';
    }

    public function getPort(): int
    {
        return 0;
    }

    public function getClientId(): string
    {
        return 'scripted';
    }

    public function getReceivedBytes(): int
    {
        return 0;
    }

    public function getSentBytes(): int
    {
        return 0;
    }

    public function registerLoopEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterLoopEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }

    public function registerPublishEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterPublishEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }

    public function registerMessageReceivedEventHandler(\Closure $callback): MqttClient
    {
        return $this;
    }

    public function unregisterMessageReceivedEventHandler(?\Closure $callback = null): MqttClient
    {
        return $this;
    }
}
