<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum JobState: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
