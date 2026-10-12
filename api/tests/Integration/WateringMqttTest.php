<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\RecordingWateringPublisher;
use App\Tests\Support\WateringBrokerProbe;
use App\Tests\Support\WateringHarness;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringConfiguration;
use App\Watering\WateringMonitorRunner;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('functional_gardenhub-watering-mqtt')]
final class WateringMqttTest extends TestCase
{
    private WateringHarness $harness;
    private RecordingWateringPublisher $publisher;

    protected function setUp(): void
    {
        $this->harness = new WateringHarness();
        $this->harness->connection->executeStatement('UPDATE watering_control SET monitor_seen_at = NULL WHERE id = 1');
        $this->publisher = new RecordingWateringPublisher();
        $this->publisher->failureMessage = 'Test OFF publish failed';
    }

    protected function tearDown(): void
    {
        $this->harness->shutdown();
    }

    public function testSuccessfulPubackDeliversExactCommand(): void
    {
        $subscriber = $this->client('ack-test-subscriber');
        $received = [];
        try {
            $subscriber->subscribe(MqttWateringPublisher::TOPIC.'/set',
                static function (string $topic, string $message) use (&$received): void {
                    $received[] = $message;
                }, MqttClient::QOS_AT_LEAST_ONCE);
            (new MqttWateringPublisher($this->configuration('mqtt', 1883)))
                ->publish(['watering_times' => 3, 'state' => 'ON']);
            $deadline = microtime(true) + 5;
            while ([] === $received && microtime(true) < $deadline) {
                $subscriber->loopOnce(microtime(true), true);
                usleep(50000);
            }
            self::assertNotEmpty($received);
            self::assertSame(['watering_times' => 3, 'state' => 'ON'], json_decode($received[0], true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testWithheldPubackTimesOutAndPublicationIsNonRetainedQosOne(): void
    {
        $broker = new WateringBrokerProbe('withhold-puback');
        try {
            $start = microtime(true);
            $this->failure(fn () => (new MqttWateringPublisher($this->configuration('127.0.0.1', $broker->port)))
                ->publish(['watering_times' => 3, 'state' => 'ON']), 'PUBACK');
            self::assertGreaterThanOrEqual(4.5, microtime(true) - $start);
        } finally {
            $packet = $broker->finish();
        }
        self::assertSame(['connectType' => 1, 'type' => 3, 'retain' => 0, 'qos' => 1,
            'topic' => MqttWateringPublisher::TOPIC.'/set'], $packet);
    }

    #[DataProvider('failedSubscriptions')]
    public function testHeartbeatNeverStartsWithoutAcceptedSuback(string $mode, string $error): void
    {
        $broker = new WateringBrokerProbe($mode);
        try {
            $runner = $this->runner('127.0.0.1', $broker->port, 1.0);
            $this->failure(fn () => $runner->runConnectionAttempt(static fn (): bool => false), $error);
            self::assertNull($this->heartbeat());
        } finally {
            self::assertSame(['connectType' => 1, 'type' => 8], $broker->finish());
        }
    }

    public function testAcceptedSubackStartsHeartbeatAndDisconnectInvalidatesIt(): void
    {
        self::assertNotNull($this->heartbeatDuring($this->runner('mqtt', 1883, 5.0)));
        self::assertNull($this->heartbeat());
    }

    public function testFailedConnectionThenReconnectRestoresHeartbeat(): void
    {
        $broker = new WateringBrokerProbe('missing-suback');
        try {
            $runner = $this->runner('127.0.0.1', $broker->port, 1.0);
            $this->failure(fn () => $runner->runConnectionAttempt(static fn (): bool => false), 'SUBACK');
            self::assertNull($this->heartbeat());
        } finally {
            self::assertSame(['connectType' => 1, 'type' => 8], $broker->finish());
        }
        self::assertNotNull($this->heartbeatDuring($this->runner('mqtt', 1883, 5.0)));
    }

    public function testFirstCallbackDatabaseFailureSurvivesFailedEmergencyOffDuringSuback(): void
    {
        $db = $this->harness->connection;
        $db->executeStatement("INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at) VALUES (1, 3, 'pending', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))");
        $db->executeStatement('UPDATE watering_control SET active_run_id = 1, monitor_seen_at = NOW() WHERE id = 1');
        $db->executeStatement("CREATE TRIGGER fail_run_update BEFORE UPDATE ON watering_run FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected run update failure'");
        $this->publisher->fail = true;
        $broker = new WateringBrokerProbe('callback-failure-during-suback');
        try {
            $runner = $this->runner('127.0.0.1', $broker->port, 5.0);
            $this->failure(fn () => $runner->runConnectionAttempt(static fn (): bool => false), 'Injected run update failure');
            self::assertSame([['state' => 'OFF']], $this->publisher->calls);
            self::assertNull($this->heartbeat());
        } finally {
            $db->executeStatement('DROP TRIGGER IF EXISTS fail_run_update');
            self::assertSame(['connectType' => 1, 'type' => 8], $broker->finish());
        }
    }

    public function testOffCallbackFailureClearsHeartbeatIgnoresLaterCallbackAndRecovers(): void
    {
        $this->publisher->fail = true;
        $runner = $this->runner('mqtt', 1883, 5.0);
        $sender = $this->client('callback-failure-sender');
        $calls = 0;
        $before = null;
        try {
            try {
                $runner->runConnectionAttempt(function () use (&$calls, &$before, $sender): bool {
                    if (1 === $calls++) {
                        $before = $this->heartbeat();
                        $sender->publish(MqttWateringPublisher::TOPIC, '{"state":"ON"}', MqttClient::QOS_AT_MOST_ONCE);
                        $sender->publish(MqttWateringPublisher::TOPIC, '{"state":"ON"}', MqttClient::QOS_AT_MOST_ONCE);
                    }
                    return false;
                });
                self::fail('Expected callback OFF error.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Test OFF publish failed', $exception->getMessage());
            }
            self::assertNotNull($before);
            self::assertSame([['state' => 'OFF'], ['state' => 'OFF']], $this->publisher->calls);
            self::assertNull($this->heartbeat());
            $this->publisher->fail = false;
            self::assertNotNull($this->heartbeatDuring($runner));
            self::assertNull($this->heartbeat());
        } finally {
            $sender->disconnect();
        }
    }

    public function testDanglingActiveRunForeignKeyCannotChangeControl(): void
    {
        try {
            $this->harness->connection->executeStatement('UPDATE watering_control SET active_run_id = 987654 WHERE id = 1');
            self::fail('Dangling active run accepted.');
        } catch (ForeignKeyConstraintViolationException $exception) {
            self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $exception);
        }
        self::assertNull(\freshControl($this->harness->manager)->getActiveRun());
    }

    private function heartbeat(): ?string
    {
        $value = $this->harness->connection->fetchOne('SELECT monitor_seen_at FROM watering_control WHERE id = 1');
        return false === $value ? null : $value;
    }

    private function heartbeatDuring(WateringMonitorRunner $runner): ?string
    {
        $calls = 0;
        $heartbeat = null;
        $runner->runConnectionAttempt(function () use (&$calls, &$heartbeat): bool {
            if (0 === $calls++) {
                return false;
            }
            $heartbeat = $this->heartbeat();
            return true;
        });
        return $heartbeat;
    }

    private function runner(string $host, int $port, float $timeout): WateringMonitorRunner
    {
        return new WateringMonitorRunner(\newTestManager($this->harness->manager, $this->publisher),
            $this->publisher, new NullLogger(), $this->configuration($host, $port), $timeout);
    }

    private function configuration(string $host, int $port): WateringConfiguration
    {
        return new WateringConfiguration(true, 'prod', $host, $port, 'test', 'test', MqttWateringPublisher::DEFAULT_TOPIC);
    }

    private function client(string $id): MqttClient
    {
        $client = new MqttClient('mqtt', 1883, $id);
        $client->connect(new ConnectionSettings(), true);
        return $client;
    }

    private function failure(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            return;
        }
        self::fail('Expected MQTT failure: '.$message);
    }

    public static function failedSubscriptions(): iterable
    {
        yield 'missing SUBACK' => ['missing-suback', 'SUBACK'];
        yield 'rejected SUBACK' => ['rejected-suback', 'QoS'];
    }
}