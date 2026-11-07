<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use App\Monolog\Telegram\InterfaceTelegramTransport;
use App\Tests\Monolog\FakeTelegramTransport;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SchedulerKernel extends Kernel
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct('dev', true);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->register('test.fake_telegram_transport',
                    FakeTelegramTransport::class)->setPublic(true);
                $container->setAlias(InterfaceTelegramTransport::class,
                    'test.fake_telegram_transport')->setPublic(true);
                $container->setAlias('test.messenger.default_bus',
                    'messenger.default_bus')->setPublic(true);
                $container->setAlias('test.scheduler_provider_default',
                    'scheduler.provider.default')->setPublic(true);
            }
        });
    }
}