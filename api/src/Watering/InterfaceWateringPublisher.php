<?php

declare(strict_types=1);

namespace App\Watering;

interface InterfaceWateringPublisher
{
    public function publish(array $command): void;
}