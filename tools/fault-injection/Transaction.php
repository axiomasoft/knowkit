<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain as D;
use KnowKit\Port\CatalogTransaction;

/** Stops a real application transaction after its SQL effects, immediately before COMMIT. */
final readonly class Transaction implements CatalogTransaction
{
    public function __construct(
        private PdoCatalogTransaction $inner,
        private bool $pause,
    ) {}

    public function run(callable $operation): mixed
    {
        return $this->inner->run(function () use ($operation): mixed {
            $result = $operation();
            if ($this->pause) {
                Process::barrier(event: 'PRECOMMIT', data: [
                    'transaction' => $this->inner->db->execute('SELECT txid_current()::text')->fetchColumn(),
                    'isolation' => $this->inner->db->execute('SHOW transaction_isolation')->fetchColumn(),
                ]);
            }

            return $result;
        });
    }

    public function lockTenant(D\TenantId $tenantId, D\LockMode $mode): void
    {
        $this->inner->lockTenant(tenantId: $tenantId, mode: $mode);
    }

    public function lockKb(D\KbId $kbId, D\LockMode $mode): void
    {
        $this->inner->lockKb(kbId: $kbId, mode: $mode);
    }

    public function lockSources(array $sourceIds, D\LockMode $mode): void
    {
        $this->inner->lockSources(sourceIds: $sourceIds, mode: $mode);
    }

    public function lockGeneration(D\GenerationId $generationId, D\LockMode $mode): void
    {
        $this->inner->lockGeneration(generationId: $generationId, mode: $mode);
    }

    public function lockJob(D\JobId $jobId, D\LockMode $mode): void
    {
        $this->inner->lockJob(jobId: $jobId, mode: $mode);
    }

    public function casPointer(D\KbId $kbId, D\SnapshotId $snapshotId, D\Decimal $expectedPointerVersion): bool
    {
        return $this->inner->casPointer(kbId: $kbId, snapshotId: $snapshotId, expectedPointerVersion: $expectedPointerVersion);
    }
}
