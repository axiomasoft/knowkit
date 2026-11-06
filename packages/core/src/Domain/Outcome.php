<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Outcome: string
{
    case Success = 'success';
    case Empty = 'empty';
    case Denied = 'denied';
    case Unknown = 'unknown';
    case Unavailable = 'unavailable';
    case Incompatible = 'incompatible';
}
