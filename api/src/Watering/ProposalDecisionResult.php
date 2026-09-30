<?php

declare(strict_types=1);

namespace App\Watering;

enum ProposalDecisionResult: string
{
    case Ignored = 'ignored';
    case Executing = WateringProposalStatus::Executing->value;
    case Approved = WateringProposalStatus::Approved->value;
    case Rejected = WateringProposalStatus::Rejected->value;
    case Expired = WateringProposalStatus::Expired->value;
    case Invalidated = WateringProposalStatus::Invalidated->value;
    case Failed = WateringProposalStatus::Failed->value;
    case Uncertain = WateringProposalStatus::Uncertain->value;
}