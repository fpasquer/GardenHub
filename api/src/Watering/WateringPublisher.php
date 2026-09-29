<?php

declare(strict_types=1);

namespace App\Watering;

interface WateringPublisher
{
    public function publish(array $command): void;
}
