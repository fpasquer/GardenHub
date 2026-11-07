<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\GrafanaSubject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-grafana-alerting')]
#[Group('functional_gardenhub-grafana')]
final class GrafanaAlertTest extends TestCase
{
    private const SILENT = 'GardenHub device silent';
    private const EMPTY = 'GardenHub no measurements received';
    private const MOISTURE = 'GardenHub soil moisture low';
    private GrafanaSubject $subject;

    protected function setUp(): void
    {
        $this->subject = new GrafanaSubject();
        $this->subject->dashboard('gardenhub-overview');
        $this->subject->dashboard('gardenhub-watering-history');
    }

    public function testActiveSensorStaysNormalWhileSilentSensorAlerts(): void
    {
        $this->subject->seed('seed-active-and-silent.sql');
        $rule = $this->subject->waitInstance(self::SILENT, 'SE01-Test', 'soil_temperature', 'Alerting');
        $this->assertInstance($rule, 'SE01-Test', 'soil_temperature', 'Alerting');
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Normal');
        self::assertSame('inactive', $this->subject->rule(self::EMPTY)['state']);
    }

    public function testGlobalSilenceFiresEachSensorAndGenuineHealthyAggregate(): void
    {
        $this->subject->seed('seed-global-silence.sql');
        foreach (['soil_temperature', 'soil_moisture'] as $type) {
            $rule = $this->subject->waitInstance(self::SILENT, 'SE01-Test', $type, 'Alerting');
            $this->assertInstance($rule, 'SE01-Test', $type, 'Alerting');
        }
        $rule = $this->subject->wait(self::EMPTY,
            static fn (array $rule): bool => 'firing' === $rule['state'], 480);
        self::assertSame('ok', $rule['health'], $rule['lastError'] ?? '');
        self::assertCount(1, $rule['alerts']);
        self::assertSame('Alerting', $rule['alerts'][0]['state']);
    }

    public function testNineStageMoistureIsolationDeadBandRecoveryAndStaleEviction(): void
    {
        $this->subject->seed('seed-soil-moisture-base.sql');
        $this->assertStaleExclusionAndFreshBaseline();
        $this->assertInterleavingAndIndependentTriggers();
        $this->assertDeadBandAndIsolatedRecovery();
        $this->assertFinalLabelsAndStaleEviction();
    }

    private function assertStaleExclusionAndFreshBaseline(): void
    {
        $this->subject->measurements('SE01-Test', [[15.8, 82], [15.5, 80], [15.2, 78]]);
        $rule = $this->subject->waitInstance(self::SILENT, 'SE01-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Alerting');
        $rule = $this->settledMoisture();
        self::assertNull($this->subject->instance($rule, 'SE01-Test', 'soil_moisture'), '3.1 stale readings');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Normal');
        $this->subject->measurements('SE01-Test', [[20.0, 30], [20.0, 20], [20.0, 10]]);
        $this->assertInstance($this->settledMoisture(), 'SE01-Test', 'soil_moisture', 'Normal');
    }

    private function assertInterleavingAndIndependentTriggers(): void
    {
        $this->subject->measurements('SE01-Test', [[15.2, 9]]);
        $this->subject->measurements('SE02-Test', [[15.4, 8]]);
        $this->subject->measurements('SE01-Test', [[15.8, 7]]);
        $rule = $this->settledMoisture();
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Normal');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Normal');
        $this->subject->measurements('SE01-Test', [[15.6, 6]]);
        $rule = $this->subject->waitInstance(self::MOISTURE, 'SE01-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Normal');
        $this->subject->measurements('SE02-Test', [[15.0, 4], [14.8, 3]]);
        $rule = $this->subject->waitInstance(self::MOISTURE, 'SE02-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Alerting');
    }

    private function assertDeadBandAndIsolatedRecovery(): void
    {
        $this->subject->measurements('SE01-Test', [[17.0, 5], [17.2, 4], [16.8, 3]]);
        $rule = $this->settledMoisture();
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Alerting');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Alerting');
        $this->subject->measurements('SE01-Test', [[19.0, 2], [18.5, 1], [18.0, 0]]);
        $rule = $this->subject->waitInstance(self::MOISTURE, 'SE01-Test', 'soil_moisture', 'Normal');
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Normal');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Alerting');
    }

    private function assertFinalLabelsAndStaleEviction(): void
    {
        $rule = $this->subject->rule(self::MOISTURE);
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Normal');
        $this->assertInstance($rule, 'SE02-Test', 'soil_moisture', 'Alerting');
        $this->subject->database->exec("UPDATE measurement m INNER JOIN sensor s ON s.id = m.sensor_id INNER JOIN device d ON d.id = s.device_id SET m.measured_at = UTC_TIMESTAMP() - INTERVAL 90 MINUTE, m.created_at = UTC_TIMESTAMP() - INTERVAL 90 MINUTE WHERE d.name = 'SE02-Test' AND s.type = 'soil_moisture'");
        $this->subject->wait(self::MOISTURE,
            fn (array $rule): bool => null === $this->subject->instance($rule, 'SE02-Test', 'soil_moisture'), 180);
        $rule = $this->subject->rule(self::MOISTURE);
        $this->assertInstance($rule, 'SE01-Test', 'soil_moisture', 'Normal');
        self::assertNull($this->subject->instance($rule, 'SE02-Test', 'soil_moisture'));
    }

    private function settledMoisture(): array
    {
        usleep(90_000_000);
        return $this->subject->rule(self::MOISTURE);
    }

    private function assertInstance(array $rule, string $device, string $type, string $state): void
    {
        $instance = $this->subject->instance($rule, $device, $type);
        self::assertNotNull($instance, $device.' '.$type);
        self::assertStringStartsWith($state, $instance['state'], $device.' '.$type);
    }
}