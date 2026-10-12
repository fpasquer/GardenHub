<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\WateringCheckCommand;
use App\Command\WateringMonitorCommand;
use App\Command\WateringProposalPollCommand;
use App\Tests\Support\ProcessEnvironment;
use App\Tests\Support\ProductionWateringKernel;
use App\Tests\Support\RecordingTelegramGateway;
use App\Tests\Support\TemporaryWorkspace;
use App\Tests\Support\WateringHarness;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringConfiguration;
use App\Watering\WateringManager;
use App\Watering\WateringReadiness;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('functional_gardenhub-watering-production')]
final class WateringProductionTest extends TestCase
{
    private WateringHarness $harness;
    private ProcessEnvironment $environment;
    private TemporaryWorkspace $workspace;
    private ?ProductionWateringKernel $kernel = null;

    protected function setUp(): void
    {
        $this->harness = new WateringHarness();
        $this->workspace = new TemporaryWorkspace();
        $values = ['WATERING_ENABLED' => 'false', 'WATERING_MQTT_TOPIC' => '',
            'WATERING_MQTT_USERNAME' => '', 'WATERING_MQTT_PASSWORD' => '',
            'WATERING_DEV_USERNAME' => 'must-not-fallback',
            'WATERING_DEV_PASSWORD' => 'must-not-fallback', 'WATERING_MQTT_HOST' => 'mqtt',
            'WATERING_MQTT_PORT' => '1883', 'TELEGRAM_ENABLED' => 'false',
            'TELEGRAM_BOT_TOKEN' => 'synthetic', 'WATERING_TELEGRAM_USER_ID' => '123',
            'WATERING_TELEGRAM_CHAT_ID' => '456', 'WATERING_MAX_SECONDS' => '30',
            'WATERING_DAILY_SECONDS' => '120', 'WATERING_COOLDOWN_SECONDS' => '60',
            'WATERING_PROPOSAL_THRESHOLD' => '15', 'WATERING_PROPOSAL_FRESHNESS_MINUTES' => '35',
            'WATERING_PROPOSAL_MAX_GAP_MINUTES' => '35',
            'WATERING_PROPOSAL_RETRY_SECONDS' => '1800',
            'WATERING_PROPOSAL_VALIDITY_MINUTES' => '30', 'WATERING_PROPOSAL_DURATION_SECONDS' => '3'];
        $this->environment = new ProcessEnvironment(array_keys($values));
        $this->configure($values);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->harness->shutdown();
        $this->environment->restore();
        unset($this->workspace);
    }

    public function testDisabledProductionDoesNotInheritDevelopmentDefaultsOrPollTelegram(): void
    {
        $this->boot();
        $container = $this->kernel->getContainer();
        $configuration = $container->get(WateringConfiguration::class);
        self::assertSame('prod', $configuration->environment);
        self::assertSame('', $configuration->username);
        self::assertSame('', $configuration->password);
        self::assertSame('', $configuration->topic);
        $this->refuses(fn () => $container->get(WateringManager::class)->request(3));
        $this->refuses(fn () => $container->get(MqttWateringPublisher::class)->publish(['state' => 'ON']));
        foreach ([WateringMonitorCommand::class, WateringProposalPollCommand::class] as $class) {
            $tester = new CommandTester($container->get($class));
            self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        }
        self::assertSame(0, $container->get(RecordingTelegramGateway::class)->polls);
    }

    public function testIncompleteProductionConfigurationDoesNotReserveRun(): void
    {
        $this->configure(['WATERING_ENABLED' => 'true']);
        $this->boot();
        $this->refuses(fn () => $this->kernel->getContainer()->get(WateringManager::class)->request(3));
        self::assertSame(0, (int) $this->harness->connection->fetchOne('SELECT COUNT(*) FROM watering_run'));
    }

    #[DataProvider('invalidRetries')]
    public function testRealContainerRejectsInvalidRetryBeforePolling(string $retry): void
    {
        $this->configure(['WATERING_PROPOSAL_RETRY_SECONDS' => $retry]);
        $this->boot();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('retry must be a positive integer');
        $this->kernel->getContainer()->get(WateringProposalPollCommand::class);
    }

    public static function invalidRetries(): array
    {
        return [['0'], ['-1'], ['abc'], ['1.5'], ['999999999999999999999']];
    }

    public function testEnabledProductionPublishesAndReadinessIsReadOnly(): void
    {
        $this->configure(['WATERING_ENABLED' => 'true',
            'WATERING_MQTT_TOPIC' => MqttWateringPublisher::DEFAULT_TOPIC,
            'WATERING_MQTT_USERNAME' => 'test', 'WATERING_MQTT_PASSWORD' => 'test']);
        $manager = $this->boot();
        $db = $manager->getConnection();
        $container = $this->kernel->getContainer();
        $watering = $container->get(WateringManager::class);
        $readiness = new WateringReadiness($db);
        $readiness->assertIdle();
        $watering->heartbeat();
        $readiness->assertMonitorReady();
        $id = $watering->request(3);
        self::assertSame('pending', \freshRun($manager, $id)?->getStatus());
        $checker = new CommandTester($container->get(WateringCheckCommand::class));
        foreach (['pending', 'running', 'uncertain', 'timed_out'] as $status) {
            $db->executeStatement('UPDATE watering_run SET status = ? WHERE id = ?', [$status, $id]);
            $before = $this->snapshot($db);
            self::assertSame(1, $checker->execute([]), $status.': '.$checker->getDisplay());
            self::assertSame($before, $this->snapshot($db), $status.' check mutated state');
        }
        $watering->acknowledgeStopped();
        self::assertSame(0, $checker->execute([]), $checker->getDisplay());
        $db->executeStatement('UPDATE watering_control SET monitor_seen_at = NULL WHERE id = 1');
        self::assertSame(1, $checker->execute(['--monitor' => true]), $checker->getDisplay());
        $db->executeStatement('UPDATE watering_control SET monitor_seen_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) WHERE id = 1');
        self::assertSame(1, $checker->execute(['--monitor' => true]), $checker->getDisplay());
        $watering->heartbeat();
        self::assertSame(0, $checker->execute(['--monitor' => true]), $checker->getDisplay());
        $poller = new CommandTester($container->get(WateringProposalPollCommand::class));
        self::assertSame(0, $poller->execute(['--once' => true]), $poller->getDisplay());
        self::assertSame(1, $container->get(RecordingTelegramGateway::class)->polls);
    }

    private function boot(): \Doctrine\ORM\EntityManagerInterface
    {
        $this->kernel = new ProductionWateringKernel($this->workspace->path);
        return \bootTestEntityManager($this->kernel);
    }

    private function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    private function snapshot(Connection $db): array
    {
        return [$db->fetchAllAssociative('SELECT * FROM watering_control'),
            $db->fetchAllAssociative('SELECT * FROM watering_run')];
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe production operation accepted.');
        } catch (\LogicException|\RuntimeException $exception) {
            self::assertStringStartsWith('Watering requires ', $exception->getMessage());
        }
    }
}