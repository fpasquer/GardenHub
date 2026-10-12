<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class BooleanFixtures
{
    public static function base(): array
    {
        return [
            ['true', true], ['yes', true], ['on', true], ['false', false],
            ['no', false], ['off', false], ['', false], ['0', false],
            ['-0', false], ['+0', false], ['0.0', false], ['-0.00e10', false],
            ['0e999999', false], ['1', true], ['2', true], ['-2', true],
            ['+2', true], ['01', true], ['0.5', true], ['-.5', true],
            ['1.', true], ['1.e2', true], ['1e3', true], ['-1e-3', true],
            ['1e+0002', true], ['1e-323', true], ['2e-324', false],
            ['3e-324', true], ['1e-400', false], ['1e309', false],
            ['1.7976931348623158e308', true],
            ['1.7976931348623159e308', false],
            ['unknown', false], ['enabled', false], ['nan', false],
            ['inf', false], ['0x10', false], ['1_000', false],
            ['1,000', false], ['1e 2', false], ['1 2', false], ['--2', false],
            ['.', false], ["\0".'1'."\0", false], ["\fyes\f", false],
            ["\f2\f", false], [mb_chr(160).'yes'.mb_chr(160), false],
            [mb_chr(160).'2'.mb_chr(160), false], ["\x1c2\x1c", false],
        ];
    }

    public static function expanded(): array
    {
        $fixtures = [[null, false]];
        foreach (self::base() as [$value, $expected]) {
            $spellings = array_unique([
                $value, strtoupper($value), ucwords(strtolower($value)),
            ]);
            foreach ($spellings as $spelling) {
                foreach (['', ' ', "\t", "\n", "\r", "\v"] as $whitespace) {
                    $fixtures[] = [$whitespace.$spelling.$whitespace, $expected];
                }
            }
        }

        return $fixtures;
    }

    public static function health(): array
    {
        return [[null, false], ...self::base(),
            [" YES\t", true], [" Off\n", false]];
    }
}