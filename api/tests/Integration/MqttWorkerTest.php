<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\MqttConsumeCommand;
use App\Tests\Support\GuardedSchema;
use App\Tests\Support\MqttLifecycleKernel;
use App\Tests\Support\MqttWorkerProbe;
use App\Tests\Support\TemporaryWorkspace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[Group('functional_gardenhub-mqtt-worker')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MqttWorkerTest extends TestCase
{
    private MqttLifecycleKernel $kernel;
    private TemporaryWorkspace $workspace;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->workspace = new TemporaryWorkspace();
        $this->kernel = new MqttLifecycleKernel($this->workspace->path);
        $this->kernel->boot();
        $manager = $this->kernel->getContainer()->get('doctrine')->getManager();
        GuardedSchema::create($manager, 'worker_lifecycle');
        $this->connection = $manager->getConnection();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        unset($this->workspace);
    }

    public function testInitialCallbacksPersistOnlyAcceptedMeasurements(): array
    {
        $this->connection->executeStatement('ALTER TABLE measurement ADD CONSTRAINT lifecycle_flush_failure CHECK (value <> 9999)');
        (new MqttWorkerProbe($this->kernel, 'initial'))->run();
        self::assertSame(41, $this->storedCount('measurement'));
        self::assertSame(1, $this->storedCount('device'));
        self::assertSame(3, $this->storedCount('sensor'));
        self::assertSame('Lifecycle sensor', $this->connection->fetchOne('SELECT name FROM device'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM measurement WHERE measured_at > '2021-01-01'"));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE value IN (42, 777, 9999)'));
        self::assertSame(20, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM measurement m JOIN sensor s ON s.id = m.sensor_id WHERE s.type = 'soil_temperature' AND m.value = 18.5"));
        self::assertSame('V', $this->connection->fetchOne("SELECT unit FROM sensor WHERE type = 'battery'"));

        return [$this->connection->fetchFirstColumn('SELECT id FROM device ORDER BY id'),
            $this->connection->fetchFirstColumn('SELECT id FROM sensor ORDER BY id')];
    }

    #[Depends('testInitialCallbacksPersistOnlyAcceptedMeasurements')]
    public function testFreshProcessRecoversWithoutDuplicatingIdentities(array $ids): void
    {
        (new MqttWorkerProbe($this->kernel, 'recovery'))->run();
        self::assertSame(42, $this->storedCount('measurement'));
        self::assertSame($ids[0], $this->connection->fetchFirstColumn('SELECT id FROM device ORDER BY id'));
        self::assertSame($ids[1], $this->connection->fetchFirstColumn('SELECT id FROM sensor ORDER BY id'));
    }

    #[Depends('testFreshProcessRecoversWithoutDuplicatingIdentities')]
    public function testResetFailureStopsAfterCurrentUplink(): void
    {
        (new MqttWorkerProbe($this->kernel, 'reset-failure'))->run();
        self::assertSame(43, $this->storedCount('measurement'));
    }

    public function testRealConnectionFailuresRetryInsteadOfReturning(): void
    {
        $stop = new \LogicException('End MQTT retry test.');
        $logger = new class($stop) extends AbstractLogger {
            public int $failures = 0;
            public function __construct(private readonly \LogicException $stop) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                TestCase::assertSame('error', $level);
                TestCase::assertStringContainsString('MQTT connection failed', (string) $message);
                if (2 === ++$this->failures) {
                    throw $this->stop;
                }
            }
        };
        $container = $this->kernel->getContainer();
        $command = new MqttConsumeCommand($container->get('test.messenger.default_bus'),
            $logger, 'mqtt', 1, '', '', 'connection-test', 'tests/lifecycle',
            $container->get('test.services_resetter'));
        try {
            $command->run(new ArrayInput([]), new NullOutput());
            self::fail('Connection failures must retry instead of returning.');
        } catch (\LogicException $exception) {
            self::assertSame($stop, $exception);
            self::assertSame(2, $logger->failures);
        }
    }

    private function storedCount(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}