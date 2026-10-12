<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BooleanFixtures;
use App\Tests\Support\ComposeModel;
use App\Tests\Support\DeploymentHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-deployment')]
final class DeploymentTest extends TestCase
{
    private DeploymentHarness $harness;
    private array $model;

    protected function setUp(): void
    {
        $this->harness = new DeploymentHarness();
        $this->model = ComposeModel::render(false,
            ['watering-hardware', 'watering-proposals'],
            ComposeModel::enabledSettings(),
        );
    }

    protected function tearDown(): void
    {
        unset($this->harness);
    }

    public function testEnabledServicesRecreatedAndDisabledSkipped(): void
    {
        ComposeModel::setEnabled($this->model, 'yes');
        $result = $this->execute('deploy-up');
        self::assertSame(0, $result['status'], $result['stderr']);
        $startup = $this->commandsWith($result, 'up')[0];
        self::assertContains('--force-recreate', $startup);
        self::assertContains('gardenhub-watering-monitor', $startup);
        self::assertContains('gardenhub-watering-proposals', $startup);
        ComposeModel::setEnabled($this->model, 'false');
        $result = $this->execute('deploy-up');
        self::assertSame(0, $result['status'], $result['stderr']);
        $startup = $this->commandsWith($result, 'up')[0];
        self::assertSame([], array_intersect($startup, ComposeModel::WATERING));
        self::assertNotEmpty(array_filter($this->commandsWith($result, 'stop'),
            static fn (array $command): bool => in_array('gardenhub-watering-monitor', $command)));
    }

    public function testBothGuardsPreserveMonitorOnRefusal(): void
    {
        $result = $this->execute('deploy-stop', ['FAKE_FAIL_GUARD' => '1']);
        self::assertNotSame(0, $result['status']);
        self::assertSame([], $this->commandsWith($result, 'stop'));
        $result = $this->execute('deploy-stop', ['FAKE_FAIL_GUARD' => '2']);
        self::assertNotSame(0, $result['status']);
        $stops = $this->commandsWith($result, 'stop');
        self::assertCount(1, $stops);
        self::assertContains('gardenhub-api', $stops[0]);
        self::assertNotContains('gardenhub-watering-monitor', $stops[0]);
    }

    public function testHealthRequiresRunningNonRestartingServices(): void
    {
        foreach ([['exited', ''], ['restarting', ''], ['running', '1']] as [$state, $restarting]) {
            $result = $this->execute('deploy-health', [
                'FAKE_STATE_SERVICE' => 'gardenhub-watering-monitor',
                'FAKE_STATE' => $state, 'FAKE_RESTARTING' => $restarting,
            ]);
            self::assertNotSame(0, $result['status'], $state);
            self::assertSame([], $this->commandsWith($result, 'curl'), $state);
        }
        $result = $this->execute('deploy-health', [
            'FAKE_MISSING' => 'gardenhub-watering-proposals',
        ]);
        self::assertNotSame(0, $result['status']);
    }

    public function testBooleanDeploymentHealthSelection(): void
    {
        foreach (BooleanFixtures::health() as [$value, $expected]) {
            ComposeModel::setEnabled($this->model, $value);
            $result = $this->execute('deploy-health');
            $label = json_encode($value, JSON_THROW_ON_ERROR);
            self::assertSame(0, $result['status'], $label);
            $inspected = array_map(static fn (array $command): string => end($command),
                $this->commandsWith($result, 'inspect'));
            foreach (['gardenhub-watering-monitor', 'gardenhub-watering-proposals'] as $name) {
                self::assertSame($expected, in_array($name, $inspected), $label);
            }
        }
    }

    public function testDisabledAndInactiveHealthServicesAreIgnored(): void
    {
        ComposeModel::setEnabled($this->model, 'false');
        $result = $this->execute('deploy-health', ['FAKE_MISSING' => 'gardenhub-watering-monitor']);
        self::assertSame(0, $result['status']);
        foreach ($this->commandsWith($result, 'inspect') as $command) {
            self::assertNotContains(end($command), ComposeModel::WATERING);
        }
        $this->model = ComposeModel::render(overrides: ComposeModel::enabledSettings());
        $result = $this->execute('deploy-health', ['FAKE_MISSING' => 'gardenhub-watering-proposals']);
        self::assertSame(0, $result['status']);
        $this->model = ComposeModel::render(overrides: ['WATERING_ENABLED' => 'true']);
        $result = $this->execute('deploy-health', ['FAKE_MISSING' => 'gardenhub-watering-monitor']);
        self::assertSame(0, $result['status']);
    }

    public function testStartMigratesBeforeApplicationStartAndFailsClosed(): void
    {
        $result = $this->execute('start');
        self::assertSame(0, $result['status'], $result['stderr']);
        $migrations = array_keys(array_filter($result['commands'],
            static fn (array $command): bool => in_array('doctrine:migrations:migrate', $command)));
        $startups = array_keys(array_filter($result['commands'],
            static fn (array $command): bool => in_array('up', $command) && in_array('gardenhub-api', $command)));
        self::assertNotEmpty($migrations);
        self::assertNotEmpty($startups);
        self::assertLessThan($startups[0], $migrations[0]);
        $result = $this->execute('start', ['FAKE_FAIL_MIGRATION' => '1']);
        self::assertNotSame(0, $result['status']);
        foreach ($this->commandsWith($result, 'up') as $command) {
            self::assertNotContains('gardenhub-api', $command);
        }
    }

    public function testSimulatorHardwareTopicRefusedBeforeBuild(): void
    {
        $this->model = ComposeModel::render(true, ['watering-sim'], ComposeModel::enabledSettings());
        $result = $this->execute('dev-sim');
        self::assertNotSame(0, $result['status']);
        self::assertSame([], $this->commandsWith($result, 'build'));
        self::assertSame([], $this->commandsWith($result, 'up'));
    }

    private function execute(string $target, array $overrides = []): array
    {
        return $this->harness->execute($target, $this->model, $overrides);
    }

    private function commandsWith(array $result, string $argument): array
    {
        return array_values(array_filter($result['commands'],
            static fn (array $command): bool => in_array($argument, $command)));
    }
}