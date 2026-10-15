<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Tests\Support\CoverageGate;
use App\Tests\Support\CoverageCollection;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

$directory = $argv[1] ?? '/reports';
$fragments = CoverageCollection::fragments($directory);
foreach ($fragments as $fragment) {
    $children = glob(dirname($fragment).'/child-*.cov');
    $fragments = [...$fragments, ...$children];
}
$merged = (new Merger())->merge($fragments);
$report = Facade::fromSerializedData($merged);
$report->renderHtml($directory.'/html');
$report->renderClover($directory.'/clover.xml');
$report->renderText($directory.'/summary.txt', showUncoveredFiles: true);
$percentage = CoverageGate::enforce($directory.'/clover.xml');
printf("Aggregate api/src line coverage: %.2f%% from %d native fragments.\n", $percentage, count($fragments));