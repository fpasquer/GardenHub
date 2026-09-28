<?php

declare(strict_types=1);

namespace App\Watering;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Psr\Log\LoggerInterface;

/** Owns one MQTT connection attempt: connect, subscribe, confirm SUBACK, then run the heartbeat loop. */
final class WateringMonitorRunner
{
    public function __construct(
        private readonly WateringManager $watering,
        private readonly WateringPublisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $topic = MqttWateringPublisher::DEFAULT_TOPIC,
        private readonly float $subackTimeoutSeconds = 10.0,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->host && '' !== $this->username && '' !== $this->password && '' !== trim($this->topic);
    }

    /** $stopRequested is polled each heartbeat iteration; production passes a closure that never stops. */
    public function runConnectionAttempt(\Closure $stopRequested): void
    {
        // Unguarded: a failure here must abort the attempt before connecting.
        $this->watering->invalidateHeartbeat();

        $repository = new MemoryRepository();
        $client = new MqttClient($this->host, $this->port, 'gardenhub-watering-monitor', repository: $repository);
        $callbackFailure = null;
        $throwCallbackFailure = static function () use (&$callbackFailure): void {
            if ($callbackFailure !== null) {
                throw $callbackFailure;
            }
        };
        try {
            $client->connect((new ConnectionSettings())->setUsername($this->username)->setPassword($this->password)->setKeepAliveInterval(30), true);
            $client->subscribe($this->topic, function (string $topic, string $message) use (&$callbackFailure): void {
                if ($callbackFailure !== null) {
                    return;
                }
                try {
                    $this->handleMessage($message);
                } catch (\Throwable $e) {
                    // php-mqtt/client catches callback exceptions, so carry the
                    // first one out of loopOnce() explicitly.
                    $callbackFailure = $e;
                }
            }, MqttClient::QOS_AT_LEAST_ONCE);
            SubscriptionReadyGate::await($client, $repository, $this->subackTimeoutSeconds, $throwCallbackFailure);
            $this->logger->info('Monitoring '.$this->topic);
            $this->runHeartbeatLoop($client, $stopRequested, $throwCallbackFailure);
        } finally {
            try {
                $this->watering->invalidateHeartbeat();
            } catch (\Throwable) {
                // Never mask the original connection/processing exception already propagating.
            }
            if ($client->isConnected()) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function handleMessage(string $message): void
    {
        try {
            $stop = $this->watering->observe($message);
            if ($stop) {
                $this->publisher->publish(['state' => 'OFF']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Watering state processing failed; trying OFF.', ['error' => $e->getMessage()]);
            // Never silently discard an ON when persistence fails.
            try {
                $this->publisher->publish(['state' => 'OFF']);
            } catch (\Throwable) {
                // Keep the processing failure as the reason to reconnect.
            }
            throw $e;
        }
    }

    private function runHeartbeatLoop(MqttClient $client, \Closure $stopRequested, \Closure $throwCallbackFailure): void
    {
        $started = microtime(true);
        $lastStopAttempt = 0.0;
        $lastHeartbeat = 0.0;
        while (!$stopRequested()) {
            $client->loopOnce($started, true);
            $throwCallbackFailure();
            if (microtime(true) - $lastHeartbeat >= 1) {
                $this->watering->heartbeat();
                $lastHeartbeat = microtime(true);
            }
            if ($this->watering->expire()) {
                $this->logger->error('Watering deadline exceeded; cycle blocked and OFF requested.');
            }
            if ($this->watering->requiresStop() && microtime(true) - $lastStopAttempt >= 5) {
                $lastStopAttempt = microtime(true);
                $this->publisher->publish(['state' => 'OFF']);
            }
        }
    }
}
