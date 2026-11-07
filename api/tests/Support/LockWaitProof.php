<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

final class LockWaitProof
{
    public static function connectionId(string $path, float $timeout = 15): int
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                $decoded = json_decode($contents, true);
                if (is_array($decoded) && isset($decoded['connectionId'])) {
                    return (int) $decoded['connectionId'];
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Writer did not report its MySQL connection ID.');
    }

    public static function wait(Connection $connection, int $requesting, int $blocking): void
    {
        $sql = 'SELECT COUNT(*) FROM performance_schema.data_lock_waits w'
            .' JOIN performance_schema.threads rt ON rt.THREAD_ID = w.REQUESTING_THREAD_ID'
            .' JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID'
            .' WHERE rt.PROCESSLIST_ID = ? AND bt.PROCESSLIST_ID = ?';
        $deadline = microtime(true) + 5;
        do {
            if ((int) $connection->fetchOne($sql, [$requesting, $blocking]) > 0) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('MySQL did not confirm the specific writer lock wait.');
    }

    public static function isTimeout(?\Throwable $exception): bool
    {
        while (null !== $exception) {
            if ($exception instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException
                || ($exception instanceof \Doctrine\DBAL\Driver\Exception
                    && 1205 === $exception->getCode())) {
                return true;
            }
            $exception = $exception->getPrevious();
        }

        return false;
    }
}