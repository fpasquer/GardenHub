<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

final class PythonSubject
{
    public static function invoke(array $request): array
    {
        $result = Command::run(
            ['python3', '-B', __DIR__.'/python_bridge.py'],
            Command::root(), ['GARDENHUB_ROOT' => Command::root()],
            json_encode($request, JSON_THROW_ON_ERROR),
        );
        if (0 !== $result['status']) {
            throw new RuntimeException(sprintf(
                'Python bridge failed (exit %d): %s',
                $result['status'], $result['stderr'],
            ));
        }

        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }
}