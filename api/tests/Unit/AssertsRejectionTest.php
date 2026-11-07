<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\AssertsRejection;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-assert-rejection')]
final class AssertsRejectionTest extends TestCase
{
    use AssertsRejection;

    public function testMatchingRejectionPasses(): void
    {
        self::assertRejected(
            \DomainException::class,
            'limit exceeded',
            static fn () => throw new \DomainException('Daily limit exceeded.'),
        );
    }

    #[DataProvider('mismatchedOutcomes')]
    public function testMismatchedOutcomeFails(\Closure $action): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertRejected(
            \DomainException::class,
            'limit exceeded',
            $action,
        );
    }

    public static function mismatchedOutcomes(): iterable
    {
        yield 'nothing thrown' => [static fn () => null];
        yield 'wrong class' => [
            static fn () => throw new \RuntimeException('Daily limit exceeded'),
        ];
        yield 'wrong message' => [
            static fn () => throw new \DomainException('Other reason.'),
        ];
    }
}
