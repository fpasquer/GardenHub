<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Command\WateringCheckCommand;
use App\Command\WateringMonitorCommand;
use App\Command\WateringProposalPollCommand;
use App\Kernel;
use App\Watering\InterfaceTelegramGateway;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringConfiguration;
use App\Watering\WateringManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProductionWateringKernel extends Kernel
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct('prod', false);
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
                $container->register(RecordingTelegramGateway::class,
                    RecordingTelegramGateway::class)->setPublic(true);
                $container->setAlias(InterfaceTelegramGateway::class, RecordingTelegramGateway::class);
                foreach ([WateringConfiguration::class, WateringManager::class,
                    MqttWateringPublisher::class, WateringMonitorCommand::class,
                    WateringProposalPollCommand::class, WateringCheckCommand::class] as $class) {
                    $container->getDefinition($class)->setPublic(true);
                }
            }
        });
    }
}