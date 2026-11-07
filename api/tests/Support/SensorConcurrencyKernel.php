<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tests\SensorIdentity\ExceptionCaptureListener;
use Tests\SensorIdentity\SensorLockObserverMiddleware;
use Tests\SensorIdentity\SensorPostLoadRelabeler;

foreach (['ExceptionCaptureListener', 'SensorLockObserverMiddleware', 'SensorPostLoadRelabeler'] as $fixture) {
    require_once Command::root().'/tests/sensor-identity/src/'.$fixture.'.php';
}

final class SensorConcurrencyKernel extends SchedulerKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register('test.sensor_lock_observer_middleware', SensorLockObserverMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->register('test.sensor_postload_relabeler', SensorPostLoadRelabeler::class)
            ->addTag('doctrine.event_listener', ['event' => 'postLoad']);
        $container->register('test.exception_capture_listener', ExceptionCaptureListener::class)
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 2048])
            ->setPublic(true);
    }
}