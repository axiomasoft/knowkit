<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Usability: string
{
    case Usable = 'usable';
    case Corrected = 'corrected';
    case Revoked = 'revoked';
}
