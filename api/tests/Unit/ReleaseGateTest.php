<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\PythonSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unitest_gardenhub-release-gate')]
final class ReleaseGateTest extends TestCase
{
    private const RUN = [
        'id' => 42, 'run_number' => 7, 'run_attempt' => 1,
        'head_branch' => 'master', 'event' => 'push',
        'path' => '.github/workflows/ci.yml',
        'status' => 'completed', 'conclusion' => 'success',
    ];

    public function testExactCommitSucceeds(): void
    {
        $result = $this->check();
        self::assertNull($result['exception']);
        self::assertSame(42, $result['value']);
    }

    #[DataProvider('invalidIdentities')]
    public function testWrongIdentityOrUnsuccessfulStateIsRefused(
        string $key, mixed $value,
    ): void {
        $run = array_replace($this->workflowRun(), [$key => $value]);
        self::assertSame('ValueError', $this->check([$run])['exception']);
    }

    public static function invalidIdentities(): array
    {
        return [
            'wrong SHA' => ['head_sha', str_repeat('b', 40)],
            'wrong branch' => ['head_branch', 'dev'],
            'pull request' => ['event', 'pull_request'],
            'wrong workflow' => ['path', '.github/workflows/other.yml'],
            'queued' => ['status', 'queued'],
            'in progress' => ['status', 'in_progress'],
            'failed' => ['conclusion', 'failure'],
            'cancelled' => ['conclusion', 'cancelled'],
            'timed out' => ['conclusion', 'timed_out'],
            'no conclusion' => ['conclusion', null],
        ];
    }

    public function testMissingAndPaginatedRunsAreRefused(): void
    {
        self::assertSame('ValueError', $this->check([], count: 0)['exception']);
        self::assertSame(
            'ValueError', $this->check([$this->workflowRun()], count: 101)['exception'],
        );
    }

    public function testNewerFailedRunWins(): void
    {
        $newer = array_replace($this->workflowRun(), [
            'id' => 43, 'run_number' => 8, 'conclusion' => 'failure',
        ]);
        self::assertSame(
            'ValueError', $this->check([$this->workflowRun(), $newer])['exception'],
        );
    }

    public function testFailedRerunWins(): void
    {
        $current = array_replace($this->workflowRun(), [
            'run_attempt' => 2, 'conclusion' => 'failure',
        ]);
        self::assertSame('ValueError', $this->check(current: $current)['exception']);
    }

    #[DataProvider('invalidJobs')]
    public function testMissingDuplicateSkippedOrFailedJobsAreRefused(
        array $jobs,
    ): void {
        self::assertSame('ValueError', $this->check(jobs: $jobs)['exception']);
    }

    public static function invalidJobs(): array
    {
        $jobs = self::jobs();

        return [
            'no jobs' => [[]],
            'missing gate' => [[$jobs[0]]],
            'duplicate gate' => [[...$jobs, $jobs[1]]],
            'skipped gate' => [[$jobs[0], array_replace(
                $jobs[1], ['conclusion' => 'skipped'],
            )]],
            'failed build' => [[array_replace(
                $jobs[0], ['conclusion' => 'failure'],
            ), $jobs[1]]],
        ];
    }

    public function testInvalidRepositoryAndShaAreRefused(): void
    {
        foreach ([['https://example.org/repo', str_repeat('a', 40)],
            ['owner/repo', 'short']] as [$repository, $sha]) {
            $result = PythonSubject::invoke([
                'operation' => 'release', 'repository' => $repository,
                'sha' => $sha, 'branch' => 'master', 'responses' => [],
            ]);
            self::assertSame('ValueError', $result['exception']);
        }
    }

    public function testTransportErrorFailsClosedWithoutSecrets(): void
    {
        $result = PythonSubject::invoke(['operation' => 'release-unavailable']);
        self::assertNull($result['exception']);
        self::assertSame(1, $result['value']);
        self::assertStringNotContainsString('secret-value', $result['stderr']);
    }

    private function check(
        ?array $runs = null, ?array $current = null,
        ?array $jobs = null, ?int $count = null,
    ): array {
        $runs ??= [$this->workflowRun()];
        $jobs ??= self::jobs();

        return PythonSubject::invoke([
            'operation' => 'release', 'repository' => 'owner/repo',
            'sha' => str_repeat('a', 40), 'branch' => 'master',
            'responses' => [
                ['workflow_runs' => $runs, 'total_count' => $count ?? count($runs)],
                $current ?? $this->workflowRun(),
                ['jobs' => $jobs, 'total_count' => count($jobs)],
            ],
        ]);
    }

    private function workflowRun(): array
    {
        return [...self::RUN, 'head_sha' => str_repeat('a', 40)];
    }

    private static function jobs(): array
    {
        return array_map(static fn (string $name): array => [
            'name' => $name, 'status' => 'completed', 'conclusion' => 'success',
        ], ['Build and integration tests', 'CI Ready']);
    }
}