<?php

declare(strict_types=1);

namespace App\Watering;

enum WateringNotificationStatus: string
{
    case New = 'new';
    case Sending = 'sending';
    case Sent = 'sent';
    case Uncertain = 'uncertain';
    case Final = 'final';
}