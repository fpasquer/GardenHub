<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait AssertsRejection
{
    /** @param class-string<\Throwable> $class */
    private static function assertRejected(
        string $class,
        string $message,
        callable $action,
    ): void {
        try {
            $action();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
            self::assertStringContainsString($message, $error->getMessage());

            return;
        }
        // Outside the try: a PHPUnit failure is a RuntimeException and
        // would be swallowed by the catch above.
        self::fail(sprintf('Expected %s "%s".', $class, $message));
    }
}
