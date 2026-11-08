<?php

declare(strict_types=1);

namespace KnowKit\Application\Observability;

use KnowKit\Domain as D;
use KnowKit\Domain\Observability as O;
use KnowKit\Port\JobQueue;

/** Keeps the underlying queue's authoritative fence/expiry result; a stale write is never retried here. */
final readonly class Jobs implements JobQueue
{
    public function __construct(
        private JobQueue $queue,
        private Telemetry $telemetry,
    ) {}

    public function enqueue(D\JobCommand $command, D\IdempotencyKey $key): D\JobId
    {
        $this->telemetry->trace->assertTenant($command->scope->tenantId);
        $id = $this->queue->enqueue(command: $command, key: $key);
        $this->telemetry->emit(event: O\Event::Stage, stage: O\Stage::Enqueue, outcome: D\Outcome::Success);

        return $id;
    }

    public function lease(D\JobType $type, D\WorkerId $worker): ?D\Lease
    {
        $lease = $this->queue->lease(type: $type, worker: $worker);
        $telemetry = $lease === null ? $this->telemetry : $this->telemetry->withLease($lease);
        $telemetry->emit(
            event: O\Event::Lease, stage: O\Stage::Lease,
            outcome: $lease === null ? D\Outcome::Empty : D\Outcome::Success,
        );

        return $lease;
    }

    public function heartbeat(D\Lease $lease): bool
    {
        $telemetry = $this->telemetry->withLease($lease);
        $accepted = $this->queue->heartbeat($lease);
        $this->conditional(telemetry: $telemetry, stage: O\Stage::Heartbeat, accepted: $accepted);

        return $accepted;
    }

    public function checkpoint(D\Lease $lease, D\Checkpoint $checkpoint): bool
    {
        $telemetry = $this->telemetry->withLease($lease);
        $accepted = $this->queue->checkpoint(lease: $lease, checkpoint: $checkpoint);
        $this->conditional(telemetry: $telemetry, stage: O\Stage::Checkpoint, accepted: $accepted);

        return $accepted;
    }

    public function complete(D\Lease $lease, D\JobResult $result): bool
    {
        $telemetry = $this->telemetry->withLease($lease);
        $accepted = $this->queue->complete(lease: $lease, result: $result);
        $this->conditional(telemetry: $telemetry, stage: O\Stage::Complete, accepted: $accepted);

        return $accepted;
    }

    public function cancel(D\Scope $scope, D\JobId $jobId): bool
    {
        $this->telemetry->trace->assertTenant($scope->tenantId);
        $accepted = $this->queue->cancel(scope: $scope, jobId: $jobId);
        $this->telemetry->emit(
            event: O\Event::Stage, stage: O\Stage::Cancel,
            outcome: $accepted ? D\Outcome::Success : D\Outcome::Denied,
        );

        return $accepted;
    }

    private function conditional(Telemetry $telemetry, O\Stage $stage, bool $accepted): void
    {
        $telemetry->emit(
            event: O\Event::Lease, stage: $stage,
            outcome: $accepted ? D\Outcome::Success : D\Outcome::Incompatible,
            reason: $accepted ? O\Reason::None : O\Reason::StaleLease,
        );
    }
}
