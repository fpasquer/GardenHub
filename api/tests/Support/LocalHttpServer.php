<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

final class LocalHttpServer
{
    private mixed $process = null;
    private string $log;
    public readonly int $port;

    public function __construct(
        string $router, ?array $environment = null, ?string $prepend = null,
    )
    {
        $this->port = self::freePort();
        $this->log = tempnam(sys_get_temp_dir(), 'gardenhub-http-');
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->log, 'a'],
            2 => ['file', $this->log, 'a'],
        ];
        $command = [PHP_BINARY];
        if (null !== $prepend) {
            array_push($command, '-d', sprintf('auto_prepend_file=%s', $prepend));
        }
        array_push($command, '-S', sprintf('127.0.0.1:%d', $this->port), $router);
        $this->process = proc_open($command, $descriptors, $pipes,
            null, $environment);
        if (!is_resource($this->process) || !$this->waitUntilReady()) {
            $diagnostics = file_get_contents($this->log, false, null, 0, 8192);
            $this->stop();
            throw new RuntimeException(sprintf(
                'Local HTTP server did not start: %s', $diagnostics,
            ));
        }
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new RuntimeException(sprintf('Cannot reserve port: %s', $error));
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    public function url(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->log) && is_file($this->log)) {
            unlink($this->log);
        }
    }

    private function waitUntilReady(): bool
    {
        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $connection = @fsockopen(
                '127.0.0.1', $this->port, $errno, $error, 0.1,
            );
            if (false !== $connection) {
                fclose($connection);

                return true;
            }
            usleep(50_000);
        }

        return false;
    }
}