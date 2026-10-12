<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\PythonSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-image-audit')]
final class ImageAuditTest extends TestCase
{
    public function testOnlyFixableHighAndCriticalFindingsBlock(): void
    {
        $findings = array_map(static fn (array $pair): array => [
            'Severity' => $pair[0], 'FixedVersion' => $pair[1],
            'VulnerabilityID' => 'CVE-test', 'PkgName' => 'package',
        ], [['HIGH', '2'], ['CRITICAL', '3'], ['HIGH', ''], ['LOW', '2']]);
        $result = PythonSubject::invoke([
            'operation' => 'audit', 'report' => [
                'SchemaVersion' => 2,
                'Results' => [['Vulnerabilities' => $findings]],
            ],
        ]);
        self::assertNull($result['exception']);
        self::assertCount(2, $result['value']);
    }

    #[DataProvider('invalidReports')]
    public function testInvalidOrEmptyReportsFail(mixed $report): void
    {
        $result = PythonSubject::invoke([
            'operation' => 'audit', 'report' => $report,
        ]);
        self::assertSame('ValueError', $result['exception']);
    }

    public static function invalidReports(): array
    {
        return [
            'missing schema' => [[]],
            'empty results' => [['SchemaVersion' => 2, 'Results' => []]],
            'null report' => [null],
        ];
    }
}