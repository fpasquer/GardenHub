<?php

namespace App\Command;

use App\Mqtt\ChirpStackUplink;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Messenger\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Subscribes to ChirpStack uplink events on the lorastack-pi MQTT broker
 * and dispatches them to Symfony Messenger for persistence.
 *
 * Long-running process, meant to run in the gardenhub-worker container.
 */
#[AsCommand(
    name: 'gardenhub:mqtt:consume',
    description: 'Consume ChirpStack device uplinks from the MQTT broker',
)]
class MqttConsumeCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $clientId,
        private readonly string $topic,
        private readonly ServicesResetterInterface $servicesResetter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = (new ConnectionSettings())
            ->setUsername('' !== $this->username ? $this->username : null)
            ->setPassword('' !== $this->password ? $this->password : null)
            ->setKeepAliveInterval(60);

        while (true) {
            $processingFailure = null;

            try {
                $client = new MqttClient($this->host, $this->port, $this->clientId);
                // Persistent session: the broker queues QoS 1 messages for this
                // client ID while the worker is disconnected.
                $client->connect($settings, false);
                $this->logger->info('Connected to MQTT broker.', ['host' => $this->host, 'topic' => $this->topic]);

                $client->subscribe($this->topic, function (string $topic, string $message) use ($client, &$processingFailure): void {
                    if (null !== $processingFailure) {
                        return;
                    }

                    try {
                        try {
                            $this->handleMessage($topic, $message);
                        } finally {
                            $this->servicesResetter->reset();
                        }
                    } catch (\Throwable $e) {
                        $processingFailure = $e;
                        $client->interrupt();
                    }
                }, MqttClient::QOS_AT_LEAST_ONCE);
                // loop() returns when the connection drops; the outer
                // while loop then reconnects after a short delay.
                $client->loop(true);
            } catch (MqttClientException $e) {
                if (null === $processingFailure) {
                    $this->logger->error('MQTT connection failed, retrying in 5s.', ['message' => $e->getMessage()]);
                }
            }

            if (null !== $processingFailure) {
                $this->logger->critical('Uplink processing or service reset failed; stopping MQTT worker for restart.', ['exception' => $processingFailure]);

                return Command::FAILURE;
            }

            sleep(5);
        }
    }

    private function handleMessage(string $topic, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            $this->logger->error('Received malformed JSON uplink; discarding.', [
                'topic' => $topic,
                'payload_length' => strlen($message),
                'payload_preview' => substr($message, 0, 128),
            ]);
            return;
        }

        $devEui = $data['deviceInfo']['devEui'] ?? null;
        if (!is_string($devEui) || '' === $devEui) {
            $this->logger->warning('Uplink without deviceInfo.devEui ignored.', ['topic' => $topic]);
            return;
        }

        $deviceName = $data['deviceInfo']['deviceName'] ?? null;
        if (!is_string($deviceName) || '' === $deviceName) {
            $deviceName = null;
        }

        $payload = $data['object'] ?? null;
        if (!is_array($payload) || [] === $payload) {
            $this->logger->info('Uplink without decoded payload ignored.', ['topic' => $topic, 'devEui' => $devEui]);
            return;
        }

        try {
            $measuredAt = isset($data['time']) ? new \DateTimeImmutable((string) $data['time']) : new \DateTimeImmutable();
        } catch (\Throwable) {
            $measuredAt = new \DateTimeImmutable();
        }

        try {
            $this->messageBus->dispatch(new ChirpStackUplink($devEui, $payload, $measuredAt, $deviceName));
        } catch (TransportExceptionInterface $e) {
            // php-mqtt/client catches callback exceptions internally, so we
            // must explicitly interrupt the loop to trigger reconnection.
            // The message may be lost if the broker already sent PUBACK.
            $this->logger->error('Failed to enqueue uplink to Messenger transport; interrupting MQTT loop for reconnect.', [
                'topic' => $topic,
                'devEui' => $devEui,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
