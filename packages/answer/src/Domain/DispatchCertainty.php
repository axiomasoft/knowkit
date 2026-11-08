<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum DispatchCertainty: string
{
    case KnownNotSent = 'knownNotSent';
    case Sent = 'sent';
    case Unknown = 'unknown';
}
