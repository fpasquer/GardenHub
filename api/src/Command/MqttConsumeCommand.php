<?php

declare(strict_types=1);

namespace App\Command;

use App\Mqtt\ChirpStackUplink;
use App\Mqtt\InterfaceMqttClientFactory;
use App\Mqtt\WorkerMqttClientFactory;
use PhpMqtt\Client\Contracts\MqttClient;
use PhpMqtt\Client\Exceptions\MqttClientException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\Messenger\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

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
    private const OUTAGE_ALERT_THRESHOLD = 12;

    private int $consecutiveConnectionFailures = 0;
    private bool $outageAlerted = false;

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
        private readonly ?InterfaceMqttClientFactory $clientFactory = null,
        private readonly ?LoggerInterface $telegramLogger = null,
        private readonly int $reconnectDelaySeconds = 5,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientFactory = $this->clientFactory
            ?? new WorkerMqttClientFactory($this->host, $this->port, $this->username, $this->password);

        while (true) {
            $processingFailure = null;
            $client = null;

            try {
                // Persistent session: the broker queues QoS 1 messages for this
                // client ID while the worker is disconnected. The factory
                // pre-registers the subscription so messages replayed by the
                // broker before our SUBACK still reach the callback.
                $client = $clientFactory->create(
                    $this->clientId,
                    $this->topic,
                    $this->createMessageCallback($client, $processingFailure),
                );
                $this->logger->info('Connected to MQTT broker.', ['host' => $this->host, 'topic' => $this->topic]);
                $this->handleSuccessfulConnection();

                // loop() returns when the connection drops; the outer
                // while loop then reconnects after a short delay.
                $client->loop(true);

                // A clean return (no exception) is still a dropped connection
                // unless it was our own processing-failure interrupt() below.
                if (null === $processingFailure) {
                    $this->recordConnectionFailure();
                }
            } catch (MqttClientException $e) {
                if (null === $processingFailure) {
                    $this->recordConnectionFailure();
                }
            }

            if (null !== $processingFailure) {
                $this->logger->critical('Uplink processing or service reset failed; stopping MQTT worker for restart.', ['exception' => $processingFailure]);

                return Command::FAILURE;
            }

            sleep($this->reconnectDelaySeconds);
        }
    }

    private function createMessageCallback(
        ?MqttClient &$client,
        ?\Throwable &$processingFailure,
    ): \Closure {
        return function (string $topic, string $message) use (&$client, &$processingFailure): void {
            if (null !== $processingFailure) {
                return;
            }

            try {
                try {
                    $this->handleMessage($topic, $message);
                } finally {
                    $this->servicesResetter->reset();
                }
            } catch (\Throwable $exception) {
                $processingFailure = $exception;
                $client?->interrupt();
            }
        };
    }

    /**
     * Resets the outage counter and, if an outage alert had fired, sends
     * exactly one recovery notification.
     */
    private function handleSuccessfulConnection(): void
    {
        $this->consecutiveConnectionFailures = 0;

        if ($this->outageAlerted) {
            $this->outageAlerted = false;
            $this->telegramLogger?->critical('MQTT worker recovered: connection to the broker was re-established.');
        }
    }

    /**
     * Counts a failed connect attempt or a dropped connection loop; fires
     * one outage alert the moment the threshold is reached.
     */
    private function recordConnectionFailure(): void
    {
        ++$this->consecutiveConnectionFailures;
        $this->logger->error(sprintf('MQTT connection failed, retrying in %ds.', $this->reconnectDelaySeconds));

        if (self::OUTAGE_ALERT_THRESHOLD === $this->consecutiveConnectionFailures) {
            $this->outageAlerted = true;
            $this->telegramLogger?->critical(sprintf(
                'MQTT worker: %d consecutive connection failures; retries continue every %ds.',
                self::OUTAGE_ALERT_THRESHOLD,
                $this->reconnectDelaySeconds
            ));
        }
    }

    private function handleMessage(string $topic, string $message): void
    {
        $uplink = $this->parseUplink($topic, $message);
        if (null === $uplink) {
            return;
        }

        try {
            $this->messageBus->dispatch($uplink);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to enqueue uplink to Messenger transport; interrupting MQTT loop for reconnect.', [
                'topic' => $topic,
                'devEui' => $uplink->devEui,
                'exception' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function parseUplink(string $topic, string $message): ?ChirpStackUplink
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            $this->logger->error('Received malformed JSON uplink; discarding.', [
                'topic' => $topic,
                'payload_length' => strlen($message),
                'payload_preview' => substr($message, 0, 128),
            ]);
            return null;
        }

        $devEui = $data['deviceInfo']['devEui'] ?? null;
        if (!is_string($devEui) || '' === $devEui) {
            $this->logger->warning('Uplink without deviceInfo.devEui ignored.', ['topic' => $topic]);
            return null;
        }

        $deviceName = $data['deviceInfo']['deviceName'] ?? null;
        if (!is_string($deviceName) || '' === $deviceName) {
            $deviceName = null;
        }

        $payload = $data['object'] ?? null;
        if (!is_array($payload) || [] === $payload) {
            $this->logger->info('Uplink without decoded payload ignored.', ['topic' => $topic, 'devEui' => $devEui]);
            return null;
        }

        // Reject events without a valid ChirpStack deduplicationId using the
        // same log-and-discard policy as other malformed uplinks.
        $deduplicationId = $data['deduplicationId'] ?? null;
        if (!is_string($deduplicationId) || !Uuid::isValid($deduplicationId)) {
            $this->logger->warning('Uplink without valid deduplicationId ignored.', ['topic' => $topic, 'devEui' => $devEui]);
            return null;
        }

        return new ChirpStackUplink(
            $devEui,
            $payload,
            $this->parseMeasuredAt($data['time'] ?? null),
            $deduplicationId,
            $deviceName,
        );
    }

    private function parseMeasuredAt(mixed $time): \DateTimeImmutable
    {
        try {
            return null !== $time
                ? new \DateTimeImmutable((string) $time)
                : new \DateTimeImmutable();
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }
}
