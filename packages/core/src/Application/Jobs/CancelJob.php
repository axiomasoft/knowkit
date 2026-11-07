<?php
declare(strict_types=1);
namespace KnowKit\Application\Jobs;

use KnowKit\Domain\{JobId, Scope};
use KnowKit\Port\JobQueue;

/** The trusted host supervisor stops the process group after durable cancellation. */
final readonly class CancelJob
{
    /** @param \Closure(JobId,int): void $stopProcessGroup */
    public function __construct(private JobQueue $queue, private \Closure $stopProcessGroup) {}
    public function cancel(Scope $scope, JobId $job): bool
    {
        if (!$this->queue->cancel($scope, $job)) { return false; }
        ($this->stopProcessGroup)($job, 5);
        return true;
    }
}
