<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Tests\Support\CoverageGate;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;
use Symfony\Component\Finder\Finder;

$directory = $argv[1] ?? '/reports';
$fragments = [];
foreach ((new Finder())->files()->name('*.cov')->in($directory) as $file) {
    $fragments[] = $file->getRealPath();
}
if ([] === $fragments) {
    throw new RuntimeException('No trusted native coverage fragments found.');
}
$merged = (new Merger())->merge($fragments);
$report = Facade::fromSerializedData($merged);
$report->renderHtml($directory.'/html');
$report->renderClover($directory.'/clover.xml');
$report->renderText($directory.'/summary.txt', showUncoveredFiles: true);
$percentage = CoverageGate::enforce($directory.'/clover.xml');
printf("Aggregate api/src line coverage: %.2f%% from %d native fragments.\n", $percentage, count($fragments));