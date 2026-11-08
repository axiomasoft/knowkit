<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

enum ReservationStatus: string
{
    case Confirmed = 'confirmed';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
