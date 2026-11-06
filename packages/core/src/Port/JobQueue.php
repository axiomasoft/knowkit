<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{JobCommand, IdempotencyKey, JobId, JobType, WorkerId, Lease, Checkpoint, JobResult, Scope};

/** Each adapter instance is bound to a trusted tenant/worker partition, never a global unscoped queue. */
interface JobQueue
{
    public function enqueue(JobCommand $command, IdempotencyKey $key): JobId;
    public function lease(JobType $type, WorkerId $worker): ?Lease;
    /** Conditional writes check tenant/source, fence, token hash, state, expiry AND deadline; false is stale. */
    public function heartbeat(Lease $lease): bool;
    public function checkpoint(Lease $lease, Checkpoint $checkpoint): bool;
    public function complete(Lease $lease, JobResult $result): bool;
    public function cancel(Scope $scope, JobId $jobId): bool;
}
