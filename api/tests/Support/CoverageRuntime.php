<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Runtime\SymfonyRuntime;

final class CoverageRuntime extends SymfonyRuntime
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        ChildCoverage::start();
    }
}