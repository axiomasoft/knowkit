<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum OutcomeCode: string
{
    case Answered = 'Answered';
    case NoEvidence = 'NoEvidence';
    case Refused = 'Refused';
    case Denied = 'Denied';
    case EgressDenied = 'EgressDenied';
    case BudgetDenied = 'BudgetDenied';
    case Cancelled = 'Cancelled';
    case Unavailable = 'Unavailable';
    case TransportUnknown = 'TransportUnknown';
    case PolicyUnknown = 'PolicyUnknown';
}
