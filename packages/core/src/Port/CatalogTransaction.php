<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{TenantId, LockMode, KbId, SourceId, GenerationId, JobId, SnapshotId, Decimal};

/** Adapter binds one tenant per transaction; require tenant guard before all later locks. */
interface CatalogTransaction
{
    public function run(callable $operation): mixed;
    public function lockTenant(TenantId $tenantId, LockMode $mode): void;
    public function lockKb(KbId $kbId, LockMode $mode): void;
    /** @param list<SourceId> $sourceIds sorted, unique */
    public function lockSources(array $sourceIds, LockMode $mode): void;
    public function lockGeneration(GenerationId $generationId, LockMode $mode): void;
    public function lockJob(JobId $jobId, LockMode $mode): void;
    /** false MUST become typed conflict/rollback at the application boundary. */
    public function casPointer(KbId $kbId, SnapshotId $snapshotId, Decimal $expectedPointerVersion): bool;
}
