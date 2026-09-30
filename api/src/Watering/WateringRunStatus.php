<?php

declare(strict_types=1);

namespace App\Watering;

enum WateringRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Uncertain = 'uncertain';
    case TimedOut = 'timed_out';
    case Reviewed = 'reviewed';
}