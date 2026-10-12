<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CoverageGate;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-coverage-gate')]
final class CoverageGateTest extends TestCase
{
    #[DataProvider('passingTotals')]
    public function testAggregateThresholdUsesExecutableLineTotals(int $covered, int $total, float $expected): void
    {
        $workspace = new TemporaryWorkspace();
        $report = $workspace->write('clover.xml', $this->xml($covered, $total));
        self::assertSame($expected, CoverageGate::enforce($report));
    }

    #[DataProvider('invalidReports')]
    public function testMissingMalformedAndBelowThresholdReportsFailClosed(?string $contents, string $message): void
    {
        $workspace = new TemporaryWorkspace();
        $report = $workspace->path.'/clover.xml';
        if (null !== $contents) {
            $workspace->write('clover.xml', $contents);
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        CoverageGate::enforce($report);
    }

    private function xml(int $covered, int $total): string
    {
        return sprintf('<coverage><project><metrics statements="%d" coveredstatements="%d"/></project></coverage>', $total, $covered);
    }

    public static function passingTotals(): iterable
    {
        yield 'exactly eighty percent' => [80, 100, 80.0];
        yield 'fully covered' => [10, 10, 100.0];
        yield 'weighted aggregate' => [81, 100, 81.0];
    }

    public static function invalidReports(): iterable
    {
        yield 'missing file' => [null, 'missing'];
        yield 'invalid XML' => ['<coverage>', 'malformed'];
        yield 'missing totals' => ['<coverage><project/></coverage>', 'malformed'];
        yield 'no executable lines' => ['<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>', 'invalid'];
        yield 'invalid total' => ['<coverage><project><metrics statements="abc" coveredstatements="0"/></project></coverage>', 'invalid'];
        yield 'impossible coverage' => ['<coverage><project><metrics statements="100" coveredstatements="101"/></project></coverage>', 'invalid'];
        yield 'below gate' => ['<coverage><project><metrics statements="100" coveredstatements="79"/></project></coverage>', 'below 80%'];
    }
}