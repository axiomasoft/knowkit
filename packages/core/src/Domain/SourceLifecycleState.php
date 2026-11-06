<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum SourceLifecycleState: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case DeletionRequested = 'deletion_requested';
}
