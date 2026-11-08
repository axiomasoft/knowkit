<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

enum Stage: string
{
    case Request = 'request';
    case Enqueue = 'enqueue';
    case Lease = 'lease';
    case Heartbeat = 'heartbeat';
    case Checkpoint = 'checkpoint';
    case Complete = 'complete';
    case Cancel = 'cancel';
    case Embed = 'embed';
    case Disclose = 'disclose';
    case Dispatch = 'dispatch';
    case Delete = 'delete';
    case Readiness = 'readiness';
}
