<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Tests\Support\Command;
use App\Tests\Support\LocalHttpServer;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

#[Group('functional_gardenhub-coverage-collection')]
final class ChildCoverageTest extends TestCase
{
    public function testCliAndHttpFragmentsMergeWithUncoveredSource(): void
    {
        $workspace = new TemporaryWorkspace();
        $support = dirname(__DIR__).'/Support';
        $environment = array_replace(getenv(), [
            'XDEBUG_MODE' => 'coverage',
            'GARDENHUB_COVERAGE_DIR' => $workspace->path,
        ]);
        $result = Command::run([
            PHP_BINARY, '-d', 'memory_limit=384M',
            '-d', sprintf('auto_prepend_file=%s/coverage_prepend.php', $support),
            $support.'/coverage_probe.php',
        ], null, $environment);
        self::assertSame(0, $result['status'],
            $result['stderr'].$result['stdout']);
        self::assertSame(['calls' => 1], json_decode($result['stdout'], true));
        self::assertCount(1, glob($workspace->path.'/*.cov'));
        $server = new LocalHttpServer($support.'/coverage_probe.php',
            $environment, $support.'/coverage_prepend.php');
        try {
            $response = file_get_contents($server->url(), false,
                stream_context_create(['http' => ['timeout' => 15]]));
            self::assertSame(['calls' => 1], json_decode($response, true));
        } finally {
            $server->stop();
        }
        $fragments = glob($workspace->path.'/*.cov');
        self::assertCount(2, $fragments);
        $merged = (new Merger())->merge($fragments);
        self::assertCount(2, $merged['testResults']);
        $report = Facade::fromSerializedData($merged);
        $report->renderClover($workspace->path.'/clover.xml');
        $xml = simplexml_load_file($workspace->path.'/clover.xml');
        $metrics = $xml->project->metrics;
        self::assertGreaterThan(0, (int) $metrics['coveredstatements']);
        self::assertGreaterThan((int) $metrics['coveredstatements'],
            (int) $metrics['statements']);
    }
}