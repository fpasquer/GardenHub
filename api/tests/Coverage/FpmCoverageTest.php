<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Tests\Support\Command;
use App\Tests\Support\TemporaryWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Serialization\Merger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('functional_gardenhub-coverage-collection')]
final class FpmCoverageTest extends TestCase
{
    public function testRealFpmHealthRequestProducesApplicationCoverage(): void
    {
        $workspace = new TemporaryWorkspace();
        $socket = $workspace->path.'/fpm.sock';
        $prepend = dirname(__DIR__).'/Support/coverage_prepend.php';
        $configuration = implode("\n", [
            '[global]', 'daemonize = no', 'error_log = /proc/self/fd/2',
            '[coverage]', 'user = www-data', 'group = www-data',
            'listen = '.$socket, 'pm = static', 'pm.max_children = 1',
            'clear_env = no', 'catch_workers_output = yes',
            'php_admin_value[auto_prepend_file] = '.$prepend,
            'php_admin_value[memory_limit] = 512M', '',
        ]);
        (new Filesystem())->dumpFile($workspace->path.'/fpm.conf', $configuration);
        $environment = array_replace(getenv(), [
            'XDEBUG_MODE' => 'coverage', 'APP_ENV' => 'dev', 'APP_DEBUG' => '0',
            'GARDENHUB_COVERAGE_DIR' => $workspace->path,
        ]);
        $process = new Process(['php-fpm', '-F', '-y', $workspace->path.'/fpm.conf'],
            '/app', $environment);
        $process->start();
        try {
            $this->awaitSocket($process, $socket);
            $response = Command::run(['cgi-fcgi', '-bind', '-connect', $socket],
                '/app', array_replace($environment, [
                    'SCRIPT_FILENAME' => '/app/public/index.php',
                    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/healthz',
                    'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_NAME' => 'localhost',
                ]));
            self::assertSame(0, $response['status'], $response['stderr']);
            self::assertStringContainsString('"status":"error"', $response['stdout'],
                substr($process->getErrorOutput(), -8192));
            self::assertStringContainsString('Database connection failed', $response['stdout']);
            $fragments = $this->awaitFragments($workspace->path);
            self::assertCount(1, $fragments);
            $merged = (new Merger())->merge($fragments);
            self::assertNotEmpty($merged['codeCoverage']->lineCoverage());
        } finally {
            $process->stop(5, 15);
        }
    }

    private function awaitSocket(Process $process, string $socket): void
    {
        $deadline = microtime(true) + 10;
        while (!file_exists($socket) && $process->isRunning()
            && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFileExists($socket, substr($process->getErrorOutput(), -8192));
    }

    private function awaitFragments(string $directory): array
    {
        $deadline = microtime(true) + 10;
        do {
            $fragments = glob($directory.'/*.cov');
            if ([] !== $fragments) {
                return $fragments;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return [];
    }
}