<?php

declare(strict_types=1);

namespace App\Command;

use App\Watering\MqttWateringPublisher;
use App\Watering\WateringManager;
use App\Watering\WateringPublisher;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gardenhub:watering:monitor', description: 'Track dev watering state and enforce its deadline')]
final class WateringMonitorCommand extends Command
{
    public function __construct(
        private readonly WateringManager $watering,
        private readonly WateringPublisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $enabled,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->enabled || $this->environment !== 'dev' || '' === $this->host || '' === $this->username || '' === $this->password) {
            $output->writeln('<error>Development watering monitor is disabled or not configured.</error>');
            return Command::FAILURE;
        }

        while (true) {
            $client = null;
            try {
                $client = new MqttClient($this->host, $this->port, 'gardenhub-watering-monitor');
                $client->connect((new ConnectionSettings())->setUsername($this->username)->setPassword($this->password)->setKeepAliveInterval(30), true);
                $client->subscribe(MqttWateringPublisher::TOPIC, function (string $topic, string $message): void {
                    try {
                        $stop = $this->watering->observe($message);
                        if ($stop) {
                            $this->publisher->publish(['state' => 'OFF']);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('Watering state processing failed; trying OFF.', ['error' => $e->getMessage()]);
                        // Never silently discard an ON when persistence fails.
                        $this->publisher->publish(['state' => 'OFF']);
                        throw $e;
                    }
                }, MqttClient::QOS_AT_LEAST_ONCE);
                $output->writeln('Monitoring '.MqttWateringPublisher::TOPIC);
                $started = microtime(true);
                $lastStopAttempt = 0.0;
                $lastHeartbeat = 0.0;
                while (true) {
                    $client->loopOnce($started, true);
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
            } catch (\Throwable $e) {
                $this->logger->error('Watering monitor connection or state failure; retrying.', ['error' => $e->getMessage()]);
                $output->writeln('<error>Watering monitor retry: '.$e->getMessage().'</error>');
                if ($client?->isConnected()) {
                    $client->disconnect();
                }
                sleep(2);
            }
        }
    }
}
