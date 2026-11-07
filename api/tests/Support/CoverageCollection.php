<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class CoverageCollection
{
    public static function fragments(string $directory): array
    {
        $manifest = $directory.'/expected-suites.txt';
        if (!is_file($directory.'/collection-complete') || !is_file($manifest)) {
            throw new \RuntimeException('Native coverage collection did not complete successfully.');
        }
        $suites = file($manifest, FILE_IGNORE_NEW_LINES);
        if (false === $suites || count($suites) < 2 || count($suites) !== count(array_unique($suites))) {
            throw new \RuntimeException('Native coverage suite manifest invalid.');
        }
        $fragments = [];
        foreach ($suites as $suite) {
            if (!preg_match('/^[a-z0-9_-]+$/D', $suite)) {
                throw new \RuntimeException('Native coverage suite name invalid.');
            }
            $fragment = $directory.'/'.$suite.'/aggregate.cov';
            self::assertSuccessfulSuite($directory.'/'.$suite.'/junit.xml');
            if (!is_readable($fragment) || 0 === filesize($fragment)) {
                throw new \RuntimeException(sprintf('Native coverage fragment missing: %s.', $suite));
            }
            $fragments[] = $fragment;
        }

        return $fragments;
    }

    private static function assertSuccessfulSuite(string $report): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = is_file($report) ? simplexml_load_file($report, options: LIBXML_NONET) : false;
            if (false === $xml) {
                throw new \RuntimeException('Native coverage JUnit report missing or malformed.');
            }
            $suites = $xml->xpath('//testsuite');
            if ([] === $suites || [] !== $xml->xpath('//failure | //error')
                || !array_filter($suites, static fn (\SimpleXMLElement $suite): bool => (int) $suite['tests'] > 0)) {
                throw new \RuntimeException('Native coverage suite failed or did not execute tests.');
            }
            foreach ($suites as $suite) {
                if ((int) $suite['failures'] > 0 || (int) $suite['errors'] > 0) {
                    throw new \RuntimeException('Native coverage suite failed.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}