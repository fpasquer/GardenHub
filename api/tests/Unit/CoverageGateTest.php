<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CoverageGate;
use App\Tests\Support\CoverageCollection;
use App\Tests\Support\Command;
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

    public function testCompleteCollectionSelectsOnlyExpectedNativeFragments(): void
    {
        $workspace = $this->collection();
        $workspace->write('unrelated.cov', 'not part of application collection');
        self::assertSame([
            $workspace->path.'/ci/aggregate.cov',
            $workspace->path.'/watering-proposals/aggregate.cov',
        ], CoverageCollection::fragments($workspace->path));
    }

    public function testCorruptNativeFragmentsCannotProducePassingCoverage(): void
    {
        $workspace = $this->collection();
        $result = Command::run([PHP_BINARY,
            dirname(__DIR__).'/Support/merge_coverage.php', $workspace->path]);
        self::assertNotSame(0, $result['status']);
        self::assertFileDoesNotExist($workspace->path.'/clover.xml');
    }

    #[DataProvider('incompleteCollections')]
    public function testIncompleteOrFailedCollectionCannotPass(string $file, ?string $contents): void
    {
        $workspace = $this->collection();
        if (null === $contents) {
            unlink($workspace->path.'/'.$file);
        } else {
            $workspace->write($file, $contents);
        }
        $this->expectException(\RuntimeException::class);
        CoverageCollection::fragments($workspace->path);
    }

    public static function incompleteCollections(): iterable
    {
        yield 'early failed run' => ['collection-complete', null];
        yield 'missing manifest' => ['expected-suites.txt', null];
        yield 'missing fragment' => ['watering-proposals/aggregate.cov', null];
        yield 'empty fragment' => ['ci/aggregate.cov', ''];
        yield 'missing JUnit' => ['ci/junit.xml', null];
        yield 'malformed JUnit' => ['ci/junit.xml', '<testsuites>'];
        yield 'failed tests despite generated coverage' => ['ci/junit.xml',
            '<testsuites><testsuite tests="2" failures="1" errors="0"/></testsuites>'];
        yield 'last suite error' => ['watering-proposals/junit.xml',
            '<testsuites><testsuite tests="2" failures="0" errors="1"/></testsuites>'];
        yield 'empty test run' => ['ci/junit.xml',
            '<testsuites><testsuite tests="0" failures="0" errors="0"/></testsuites>'];
        yield 'duplicate suite' => ['expected-suites.txt', "ci\nci\n"];
        yield 'unsafe suite' => ['expected-suites.txt', "ci\n../stale\n"];
    }

    private function collection(): TemporaryWorkspace
    {
        $workspace = new TemporaryWorkspace();
        $workspace->write('expected-suites.txt', "ci\nwatering-proposals\n");
        $workspace->write('collection-complete', '');
        foreach (['ci', 'watering-proposals'] as $suite) {
            $workspace->write($suite.'/aggregate.cov', 'fragment existence fixture only');
            $workspace->write($suite.'/junit.xml',
                '<testsuites><testsuite tests="2" failures="0" errors="0"/></testsuites>');
        }

        return $workspace;
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