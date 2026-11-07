<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Watering\ActuatorSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-actuator-safety')]
final class ActuatorSafetyTest extends TestCase
{
    #[DataProvider('reports')]
    public function testReportIsJudgedFailClosed(
        array $report,
        bool $confirmedSafe,
        bool $unsafe,
    ): void {
        self::assertSame($confirmedSafe, ActuatorSafety::isConfirmedSafe($report));
        self::assertSame($unsafe, ActuatorSafety::isUnsafe($report));
    }

    public static function reports(): iterable
    {
        // Shape published by zigbee2mqtt for the real pump: no battery_low.
        $real = [
            'state' => 'OFF',
            'alarm_1' => false,
            'battery' => 100,
            'voltage' => 6000,
            'watering_times' => 65,
        ];
        $without = static function (string $key) use ($real): array {
            unset($real[$key]);

            return $real;
        };

        yield 'real pump at rest' => [$real, true, false];
        yield 'lowest healthy reading seen on the real pump' => [
            array_replace($real, ['battery' => 86.5]), true, false,
        ];
        yield 'battery just above the limit' => [
            array_replace($real, ['battery' => 21]), true, false,
        ];
        yield 'battery at the limit' => [
            array_replace($real, ['battery' => 20]), false, true,
        ];
        yield 'battery empty' => [
            array_replace($real, ['battery' => 0]), false, true,
        ];
        yield 'alarm raised with a full battery' => [
            array_replace($real, ['alarm_1' => true]), false, true,
        ];
        yield 'alarm not reported' => [$without('alarm_1'), false, false];
        yield 'no battery information at all' => [
            $without('battery'), false, false,
        ];
        yield 'pump flag false beats a low percentage' => [
            array_replace($real, ['battery_low' => false, 'battery' => 5]),
            true, false,
        ];
        yield 'pump flag true beats a full percentage' => [
            array_replace($real, ['battery_low' => true]), false, true,
        ];
        yield 'malformed pump flag is never trusted' => [
            array_replace($real, ['battery_low' => 'false']), false, false,
        ];
        yield 'null pump flag is never trusted' => [
            array_replace($real, ['battery_low' => null]), false, false,
        ];
        yield 'percentage above 100 is implausible' => [
            array_replace($real, ['battery' => 150]), false, false,
        ];
        yield 'negative percentage is implausible' => [
            array_replace($real, ['battery' => -1]), false, false,
        ];
        yield 'infinite percentage is implausible' => [
            array_replace($real, ['battery' => INF]), false, false,
        ];
        yield 'percentage sent as text is never trusted' => [
            array_replace($real, ['battery' => '100']), false, false,
        ];
    }
}
