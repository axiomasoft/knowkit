<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Domain as D;
use KnowKit\Tests\Jobs\Leases\Fixture as JobFixture;

final readonly class Jobs
{
    public function __construct(
        private Runtime $runtime,
    ) {}

    public function execute(string $operation): array
    {
        $r = $this->runtime;
        if ($operation === 'job-write') {
            return $this->sharedWrite();
        }
        $queue = JobFixture::makeQueue(db: $r->db, scope: $r->scope, directory: $r->options['directory']);
        if ($operation === 'enqueue') {
            $job = $queue->enqueue(command: $r->options['command'], key: new D\IdempotencyKey('two-ingest'));
            Process::barrier(event: 'ENQUEUED', data: ['job' => $job->value]);
            $lease = $queue->lease(type: new D\JobType('ingest'), worker: new D\WorkerId(Fixture::id($r->options['worker'])));

            return ['job' => $job->value, 'claimed' => $lease !== null, 'fence' => $lease?->fence->value];
        }
        if ($operation === 'claim' || $operation === 'takeover') {
            $lease = $queue->lease(type: new D\JobType('extract'), worker: new D\WorkerId(Fixture::id(902)));
            if ($lease === null || $operation === 'claim') {
                return ['claimed' => $lease !== null];
            }
            $resumed = $queue->resume(lease: $lease, processing: $r->options['processing']);

            return [
                'claimed' => true,
                'fence' => $lease->fence->value,
                'resumed' => $resumed !== null,
                'completed' => $queue->complete(lease: $lease, result: $r->options['result']),
            ];
        }
        $lease = $r->options['lease'];
        if ($operation === 'complete') {
            return ['completed' => $queue->complete(lease: $lease, result: $r->options['result'])];
        }

        return [
            'heartbeat' => $queue->heartbeat($lease),
            'checkpoint' => $queue->checkpoint(lease: $lease, checkpoint: $r->options['checkpoint']),
            'resumed' => $queue->resume(lease: $lease, processing: $r->options['processing']) !== null,
            'completed' => $queue->complete(lease: $lease, result: $r->options['result']),
        ];
    }

    private function sharedWrite(): array
    {
        $r = $this->runtime;
        $r->tx->run(function () use ($r): void {
            $r->tx->lockTenant(tenantId: $r->tenant, mode: D\LockMode::Share);
            $r->tx->lockKb(kbId: $r->scope->kbId, mode: D\LockMode::Share);
            $r->tx->lockSources(sourceIds: [new D\SourceId(Fixture::id(2))], mode: D\LockMode::Share);
            $r->tx->lockJob(jobId: $r->options['job'], mode: D\LockMode::Update);
            Process::barrier('GUARDED');
            $r->db->execute(
                "UPDATE jobs SET last_error_code='RESILIENCE_GUARD_PROBE' WHERE tenant_id=? AND id=?",
                [$r->tenant->value, $r->options['job']->value],
            );
        });

        return ['status' => 'committed'];
    }
}
