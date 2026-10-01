<?php

declare(strict_types=1);

namespace App\Watering;

enum ActuatorState: string
{
    case On = 'ON';
    case Off = 'OFF';
}