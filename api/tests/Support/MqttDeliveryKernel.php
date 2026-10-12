<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Monolog\Telegram\InterfaceTelegramTransport;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Tests\MqttLifecycle\CollisionMiddleware;
use Tests\MqttLifecycle\FakeTelegramTransport;
use Tests\MqttLifecycle\ScenarioSubscriber;
use Tests\MqttLifecycle\SensorRelabelMiddleware;

foreach (['CollisionMiddleware', 'FakeTelegramTransport', 'ScenarioRecorder', 'ScenarioSubscriber', 'SensorRelabelMiddleware'] as $fixture) {
    require_once Command::root().'/tests/mqtt-lifecycle/src/'.$fixture.'.php';
}

final class MqttDeliveryKernel extends SchedulerKernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(Command::root().'/tests/mqtt-lifecycle/config/packages/messenger_test_retry.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register('test.collision_middleware', CollisionMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->register('test.sensor_relabel_middleware', SensorRelabelMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->setAlias('test.services_resetter', 'services_resetter')->setPublic(true);
        $container->register('test.scenario_subscriber', ScenarioSubscriber::class)
            ->setArguments([new Reference('messenger.default_bus'), new Reference('doctrine.orm.entity_manager')])
            ->addTag('kernel.event_subscriber')->setPublic(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->register(FakeTelegramTransport::class, FakeTelegramTransport::class)->setPublic(true);
                $container->setAlias(InterfaceTelegramTransport::class, FakeTelegramTransport::class)->setPublic(false);
                $container->setAlias('test.telegram_transport', FakeTelegramTransport::class)->setPublic(true);
            }
        });
    }
}