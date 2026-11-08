<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

enum Event: string
{
    case Stage = 'stage';
    case Lease = 'lease';
    case Provider = 'provider';
    case Index = 'index_readiness';
    case Disclosure = 'disclosure';
    case Outbox = 'outbox';
    case Purge = 'purge';
    case Quota = 'quota';
}
