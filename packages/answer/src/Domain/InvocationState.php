<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum InvocationState: string
{
    case Preparing = 'preparing';
    case Reserved = 'reserved';
    case Sending = 'sending';
    case Completed = 'completed';
    case Unknown = 'unknown';
}
