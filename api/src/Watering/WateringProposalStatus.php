<?php

declare(strict_types=1);

namespace App\Watering;

enum WateringProposalStatus: string
{
    case Pending = 'pending';
    case Executing = 'executing';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}