<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class CoverageGate
{
    public static function enforce(string $report, int $threshold = 80): float
    {
        if (!is_file($report)) {
            throw new \RuntimeException('Aggregate Clover report missing.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($report, options: LIBXML_NONET);
            if (false === $xml || 1 !== count($xml->xpath('/coverage/project/metrics'))) {
                throw new \RuntimeException('Aggregate Clover report malformed.');
            }
            $metrics = $xml->project->metrics;
            $statements = filter_var((string) $metrics['statements'], FILTER_VALIDATE_INT);
            $covered = filter_var((string) $metrics['coveredstatements'], FILTER_VALIDATE_INT);
            if (false === $statements || $statements <= 0 || false === $covered
                || $covered < 0 || $covered > $statements) {
                throw new \RuntimeException('Aggregate coverage totals invalid.');
            }
            $percentage = 100 * $covered / $statements;
            if ($percentage < $threshold) {
                throw new \RuntimeException(sprintf('Aggregate api/src line coverage %.2f%% is below %d%% (%d/%d).',
                    $percentage, $threshold, $covered, $statements));
            }
            return $percentage;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}