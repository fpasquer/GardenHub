<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

final class TemporaryWorkspace
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sprintf('%s/gardenhub-test-%s',
            sys_get_temp_dir(), bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->path);
    }

    public function write(string $name, string $content, bool $executable = false): string
    {
        $path = sprintf('%s/%s', $this->path, $name);
        (new Filesystem())->mkdir(dirname($path));
        (new Filesystem())->dumpFile($path, $content);
        if ($executable) {
            chmod($path, 0755);
        }

        return $path;
    }

    public function __destruct()
    {
        (new Filesystem())->remove($this->path);
    }
}