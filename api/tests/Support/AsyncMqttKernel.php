<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AsyncMqttKernel extends SchedulerKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.services_resetter', 'services_resetter')->setPublic(true);
    }
}