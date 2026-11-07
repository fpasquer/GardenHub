<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class ChildCoverage
{
    public static function start(): void
    {
        $directory = getenv('GARDENHUB_COVERAGE_DIR');
        if (false === $directory || '' === $directory) {
            return;
        }
        if (!function_exists('xdebug_info')
            || !in_array('coverage', xdebug_info('mode'), true)) {
            throw new RuntimeException('Child coverage requires Xdebug coverage mode.');
        }
        (new Filesystem())->mkdir($directory);
        if (!is_writable($directory)) {
            throw new RuntimeException('Child coverage directory is not writable.');
        }
        $filter = new Filter();
        $files = (new Finder())->files()->name('*.php')->in(
            dirname(__DIR__, 2).'/src',
        );
        foreach ($files as $file) {
            $filter->includeFile($file->getRealPath());
        }
        $coverage = new CodeCoverage((new Selector())->select($filter), $filter);
        $id = sprintf('child-%d-%s', getmypid(), bin2hex(random_bytes(8)));
        $coverage->start($id);
        register_shutdown_function(static function () use (
            $coverage, $directory, $id,
        ): void {
            $coverage->stop();
            $target = sprintf('%s/%s.cov', $directory, $id);
            (new Serializer())->serialize($target.'.tmp', $coverage);
            (new Filesystem())->rename($target.'.tmp', $target);
        });
    }
}