<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BooleanFixtures;
use App\Tests\Support\ComposeModel;
use App\Tests\Support\PythonSubject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-compose-configuration')]
final class ComposeConfigurationTest extends TestCase
{
    public function testFullBooleanMatrixSelectsServices(): void
    {
        $model = ComposeModel::render(false,
            ['watering-hardware', 'watering-proposals'],
            ComposeModel::enabledSettings(),
        );
        foreach (array_chunk(BooleanFixtures::expanded(), 32) as $fixtures) {
            foreach (['hardware', 'deploy'] as $mode) {
                $result = PythonSubject::invoke([
                    'operation' => 'selection-values', 'model' => $model,
                    'mode' => $mode, 'values' => array_column($fixtures, 0),
                ]);
                self::assertNull($result['exception']);
                self::assertCount(count($fixtures), $result['value']);
                foreach ($fixtures as $index => [$value, $expected]) {
                    $selected = $result['value'][$index];
                    $label = sprintf('%s %s', $mode, json_encode($value));
                    foreach (['gardenhub-watering-monitor',
                        'gardenhub-watering-proposals'] as $name) {
                        self::assertSame($expected, in_array($name, $selected), $label);
                    }
                    if ('deploy' === $mode) {
                        self::assertSame([], array_diff(ComposeModel::APPLICATION, $selected));
                    }
                }
            }
        }
    }

    public function testProductionIsImageBackedWithoutDevelopmentFallbacks(): void
    {
        $model = ComposeModel::render(false, ['*'], $this->legacyCredentials());
        self::assertSame('production', $model['services']['gardenhub-api']['build']['target']);
        self::assertArrayNotHasKey('gardenhub-watering-sim', $model['services']);
        self::assertArrayNotHasKey('gardenhub-adminer', $model['services']);
        foreach ($this->phpServices() as $name) {
            $service = $model['services'][$name];
            self::assertSame('prod', $service['environment']['APP_ENV']);
            self::assertSame('0', $service['environment']['APP_DEBUG']);
            self::assertEmpty($service['volumes'] ?? []);
            self::assertEmpty($service['extra_hosts'] ?? []);
            foreach (['USERNAME', 'PASSWORD', 'TOPIC'] as $suffix) {
                self::assertEmpty($service['environment']['WATERING_MQTT_'.$suffix]);
            }
            self::assertArrayHasKey('WATERING_PROPOSAL_DURATION_SECONDS', $service['environment']);
        }
        self::assertSame(['watering-hardware'], $model['services']['gardenhub-watering-monitor']['profiles']);
        self::assertSame(['watering-proposals'], $model['services']['gardenhub-watering-proposals']['profiles']);
    }

    public function testDevelopmentOverridesAndNeutralCredentialsTakePriority(): void
    {
        $model = ComposeModel::render(true, ['*'], $this->legacyCredentials());
        self::assertSame('development', $model['services']['gardenhub-api']['build']['target']);
        foreach ($this->phpServices() as $name) {
            $service = $model['services'][$name];
            self::assertSame('dev', $service['environment']['APP_ENV']);
            self::assertSame('1', $service['environment']['APP_DEBUG']);
            self::assertNotEmpty($service['volumes']);
            self::assertNotEmpty($service['extra_hosts']);
            self::assertSame('legacy', $service['environment']['WATERING_MQTT_USERNAME']);
            self::assertSame(ComposeModel::SIM_TOPIC, $service['environment']['WATERING_MQTT_TOPIC']);
        }
        self::assertSame(['watering-sim', 'watering-hardware'],
            $model['services']['gardenhub-watering-monitor']['profiles']);
        $model = ComposeModel::render(true, ['*'], array_replace(
            $this->legacyCredentials(), [
                'WATERING_MQTT_USERNAME' => 'neutral',
                'WATERING_MQTT_PASSWORD' => 'neutral',
            ],
        ));
        foreach ($this->phpServices() as $name) {
            foreach (['USERNAME', 'PASSWORD'] as $suffix) {
                self::assertSame('neutral', $model['services'][$name]['environment']['WATERING_MQTT_'.$suffix]);
            }
        }
    }

    public function testProfilesEnablementAndDefaults(): void
    {
        self::assertArrayNotHasKey('gardenhub-watering-monitor', ComposeModel::render()['services']);
        self::assertArrayNotHasKey('gardenhub-watering-sim', ComposeModel::render(true, ['watering-hardware'])['services']);
        self::assertArrayHasKey('gardenhub-watering-monitor', ComposeModel::render(true, ['watering-sim'])['services']);
        $model = ComposeModel::render(false, ['watering-hardware', 'watering-proposals']);
        self::assertSame([], array_intersect($this->select($model), ComposeModel::WATERING));
        $model = ComposeModel::render(false, ['watering-hardware', 'watering-proposals'], ComposeModel::enabledSettings());
        self::assertSame([], array_diff([
            ...ComposeModel::APPLICATION, 'gardenhub-watering-monitor',
            'gardenhub-watering-proposals',
        ], $this->select($model)));
        $environment = $model['services']['gardenhub-api']['environment'];
        foreach (['MAX_SECONDS' => '30', 'DAILY_SECONDS' => '120',
            'COOLDOWN_SECONDS' => '60', 'PROPOSAL_DURATION_SECONDS' => '3'] as $suffix => $expected) {
            self::assertSame($expected, $environment['WATERING_'.$suffix]);
        }
    }

    public function testInvalidModesAndConfigurationAreRefused(): void
    {
        $this->assertRefused(ComposeModel::render(true,
            ['watering-sim'], ComposeModel::enabledSettings()), 'start');
        $this->assertRefused(ComposeModel::render(false,
            ['watering-proposals'], ComposeModel::enabledSettings()));
        foreach (['USERNAME', 'PASSWORD', 'TOPIC'] as $suffix) {
            $settings = array_replace(ComposeModel::enabledSettings(), [
                'WATERING_MQTT_'.$suffix => '',
            ]);
            $this->assertRefused(ComposeModel::render(false,
                ['watering-hardware'], $settings));
        }
    }

    private function select(array $model): array
    {
        $result = PythonSubject::invoke([
            'operation' => 'selection', 'model' => $model, 'mode' => 'deploy',
        ]);
        self::assertNull($result['exception']);

        return $result['value'];
    }

    private function assertRefused(array $model, string $mode = 'deploy'): void
    {
        $result = PythonSubject::invoke([
            'operation' => 'selection', 'model' => $model, 'mode' => $mode,
        ]);
        self::assertSame('ValueError', $result['exception']);
    }

    private function legacyCredentials(): array
    {
        return ['WATERING_DEV_USERNAME' => 'legacy',
            'WATERING_DEV_PASSWORD' => 'legacy'];
    }

    private function phpServices(): array
    {
        return [...array_diff(ComposeModel::APPLICATION, ['gardenhub-nginx']),
            'gardenhub-watering-monitor', 'gardenhub-watering-proposals'];
    }
}