<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MqttLifecycleKernel extends SchedulerKernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(Command::root().'/tests/mqtt-lifecycle/config/packages/messenger_sync.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach (['messenger.default_bus', 'services_resetter', 'doctrine.debug_data_holder'] as $service) {
            $container->setAlias('test.'.$service, $service)->setPublic(true);
        }
    }
}