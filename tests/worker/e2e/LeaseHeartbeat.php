<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Domain as D;
use KnowKit\Port\JobQueue;

/** Host lease maintenance; the queue remains authoritative for fence, token, epoch and expiry. */
final class LeaseHeartbeat
{
    /** @var array<string, int> */
    private array $next = [];

    public function __construct(
        private JobQueue $queue,
        private ?\Closure $monotonic = null,
    ) {
    }

    public function tick(D\Lease $lease): void
    {
        $now = $this->monotonic === null ? hrtime(true) : ($this->monotonic)();
        $key = $lease->jobId->value . ':' . $lease->attemptId->value . ':' . $lease->fence->value;
        if ($now < ($this->next[$key] ?? 0)) {
            return;
        }
        if (!$this->queue->heartbeat($lease)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'stale_lease');
        }
        $this->next[$key] = $now + 15_000_000_000;
    }
}
