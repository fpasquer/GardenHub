<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MigrationKernel extends SchedulerKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.migrations_dependency_factory',
            'doctrine.migrations.dependency_factory')->setPublic(true);
    }
}