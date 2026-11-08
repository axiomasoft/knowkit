<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Lease;
use KnowKit\Domain\WorkerJobContext;

interface WorkerJobs extends JobQueue
{
    /** Authoritative token, fence, epoch, lifecycle and expiry check; null is stale. */
    public function workerContext(Lease $lease): ?WorkerJobContext;
}
