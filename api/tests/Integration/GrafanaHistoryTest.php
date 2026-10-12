<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\GrafanaSubject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-grafana-watering-history')]
#[Group('functional_gardenhub-grafana')]
final class GrafanaHistoryTest extends TestCase
{
    private GrafanaSubject $subject;

    protected function setUp(): void
    {
        $this->subject = new GrafanaSubject();
    }

    public function testProvisionedHistoryCalibrationNullsOrderingRangesAndDailyOutcomes(): void
    {
        $dashboard = $this->subject->dashboard('gardenhub-watering-history');
        $overview = $this->subject->dashboard('gardenhub-overview');
        $this->assertProvisioning($dashboard, 'gardenhub-watering-history');
        $this->assertProvisioning($overview, 'gardenhub-overview');
        self::assertSame('GardenHub '."\u{2014}".' Watering History', $dashboard['dashboard']['title']);
        self::assertCount(2, $dashboard['dashboard']['panels']);
        $table = $this->panel($dashboard, 'Watering history');
        $overviewTable = $this->panel($overview, 'Watering history');
        $comparison = $table;
        unset($comparison['gridPos'], $comparison['id'], $overviewTable['gridPos'], $overviewTable['id']);
        self::assertSame($comparison, $overviewTable);
        $this->assertTableConfiguration($table);
        $this->subject->database->exec('DELETE FROM watering_run');
        self::assertSame([], $this->subject->rows($this->subject->frame($table, 1767225600000, 1767229200000)));
        $this->subject->seed('seed-watering-history.sql');
        self::assertSame(21, (int) $this->subject->database->query('SELECT COUNT(*) FROM watering_run')->fetchColumn());
        $this->assertHistoryRows($this->subject->frame($table, 1767225600000, 1767229200000));
        self::assertSame([3, 2], $this->subject->frame($table, 1767225720000, 1767225780000)['data']['values'][0]);
        $this->assertDailyOutcomes($this->panel($dashboard, 'Run outcomes'), $table);
    }

    private function assertProvisioning(array $dashboard, string $uid): void
    {
        self::assertSame($uid, $dashboard['dashboard']['uid']);
        self::assertSame('GardenHub', $dashboard['meta']['folderTitle']);
        self::assertStringEndsWith($uid.'.json', $dashboard['meta']['provisionedExternalId']);
    }

    private function panel(array $dashboard, string $title): array
    {
        $matches = array_values(array_filter($dashboard['dashboard']['panels'],
            static fn (array $panel): bool => $panel['title'] === $title));
        self::assertCount(1, $matches, $title);
        return $matches[0];
    }

    private function assertTableConfiguration(array $table): void
    {
        self::assertSame('table', $table['type']);
        self::assertSame('table', $table['targets'][0]['format']);
        self::assertSame('gardenhub-mysql', $table['targets'][0]['datasource']['uid']);
        self::assertSame('', $table['fieldConfig']['defaults']['noValue']);
        self::assertSame([], $table['options']['sortBy']);
        foreach ([['Started at', 'unit', 'dateTimeAsIso'], ['Ended at', 'unit', 'dateTimeAsIso'],
            ['Actual duration (seconds)', 'unit', 'suffix:s'],
            ['Estimated volume (ml)', 'unit', 'suffix:ml'],
            ['Estimated volume (ml)', 'decimals', 1]] as [$name, $property, $expected]) {
            $values = [];
            foreach ($table['fieldConfig']['overrides'] as $override) {
                if ($override['matcher']['options'] === $name) {
                    foreach ($override['properties'] as $setting) {
                        if ($setting['id'] === $property) {
                            $values[] = $setting['value'];
                        }
                    }
                }
            }
            self::assertSame($expected, $values[0] ?? null);
        }
        $this->assertRunOnlySql($table);
    }

    private function assertRunOnlySql(array $panel): void
    {
        self::assertStringContainsString('FROM watering_run', $panel['targets'][0]['rawSql']);
        self::assertDoesNotMatchRegularExpression('/join|requested_seconds|device/i', $panel['targets'][0]['rawSql']);
    }

    private function assertHistoryRows(array $frame): void
    {
        self::assertSame(['Run ID', 'Status', 'Started at', 'Ended at',
            'Actual duration (seconds)', 'Estimated volume (ml)'], array_column($frame['schema']['fields'], 'name'));
        self::assertSame(['number', 'string', 'time', 'time', 'number', 'number'], array_column($frame['schema']['fields'], 'type'));
        self::assertSame([20, 19, 14, 13, 12, 11, 10, 9, 8, 21, 7, 6, 5, 4, 3, 2, 1, 18], $frame['data']['values'][0]);
        $rows = $this->subject->rows($frame);
        usort($rows, static fn (array $first, array $second): int => $first[0] <=> $second[0]);
        $expected = [[1, 67, 500], [2, 10, 74.6], [3, 3, 22.4], [4, 0, 0],
            [5, null, null], [6, null, null], [7, null, null], [8, null, null],
            [9, null, null], [10, null, null], [11, null, null], [12, null, null],
            [13, null, null], [14, 10, 74.6], [18, 3, 22.4], [19, null, null],
            [20, 3, 22.4], [21, null, null]];
        foreach ($rows as $index => $row) {
            self::assertSame($expected[$index][0], $row[0]);
            self::assertSame($expected[$index][1], $row[4]);
            if (null === $expected[$index][2]) {
                self::assertNull($row[5]);
            } else {
                self::assertIsNumeric($row[5]);
                self::assertEquals($expected[$index][2], $row[5]);
            }
        }
        $byId = array_column($rows, null, 0);
        self::assertSame([1767225660000, 1767225727000], array_slice($byId[1], 2, 2));
        self::assertNull($byId[5][2]);
        self::assertNull($byId[6][3]);
        self::assertSame([null, null], array_slice($byId[7], 2, 2));
    }

    private function assertDailyOutcomes(array $chart, array $table): void
    {
        self::assertSame('barchart', $chart['type']);
        self::assertSame('normal', $chart['options']['stacking']);
        self::assertSame('vertical', $chart['options']['orientation']);
        self::assertSame('Day', $chart['options']['xField']);
        self::assertTrue($chart['options']['legend']['showLegend']);
        self::assertSame(0, $chart['fieldConfig']['defaults']['decimals']);
        self::assertLessThanOrEqual($table['gridPos']['y'], $chart['gridPos']['y'] + $chart['gridPos']['h']);
        self::assertSame('table', $chart['targets'][0]['format']);
        $this->assertRunOnlySql($chart);
        $frame = $this->subject->frame($chart, 1767225600000, 1767229200000);
        self::assertSame(['Day', 'Completed', 'Timed out', 'Uncertain', 'Reviewed', 'Pending', 'Running'], array_column($frame['schema']['fields'], 'name'));
        self::assertSame(['time', 'number', 'number', 'number', 'number', 'number', 'number'], array_column($frame['schema']['fields'], 'type'));
        self::assertSame([[1767225600000, 11, 1, 1, 1, 3, 1]], $this->subject->rows($frame));
        self::assertSame([[1767225600000, 2, 0, 0, 0, 0, 0]], $this->subject->rows($this->subject->frame($chart, 1767225720000, 1767225780000)));
        self::assertSame([], $this->subject->rows($this->subject->frame($chart, 1767139200000, 1767142800000)));
        $this->subject->database->exec('INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at, started_at, finished_at) SELECT id + 100, requested_seconds, status, DATE_ADD(requested_at, INTERVAL 1 DAY), DATE_ADD(deadline_at, INTERVAL 1 DAY), DATE_ADD(started_at, INTERVAL 1 DAY), DATE_ADD(finished_at, INTERVAL 1 DAY) FROM watering_run WHERE id IN (1,9,10,11,12,19)');
        self::assertSame([[1767225600000, 11, 1, 1, 1, 4, 1], [1767312000000, 1, 1, 1, 1, 1, 1]],
            $this->subject->rows($this->subject->frame($chart, 1767225600000, 1767315600000)));
    }
}