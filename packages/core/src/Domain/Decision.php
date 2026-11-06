<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum Decision: string
{
    case Allow = 'Allow';
    case Deny = 'Deny';
    case Unknown = 'Unknown';
}
