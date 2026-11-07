<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** SQL-only primitives; caller holds the ordered semantic guards. */
interface LifecycleCommandStore extends LifecycleStore
{
    /** Lock affected generations, then jobs, before any service/outbox row is changed. */
    public function lockWork(D\Scope $scope, D\SourceId $sourceId): void;
    /** @param \Closure():JsonObject $mutate */
    public function executeIdempotent(D\Scope $scope, D\SourceId $sourceId, D\Operation $operation,
        D\IdempotencyKey $key, JsonObject $businessFields, ?D\Decimal $expectedEpoch, \Closure $mutate): JsonObject;
    public function purgeSource(D\Scope $scope, D\PurgeRequest $request): ?D\DeletionTaskId;
    public function activateSnapshot(D\Scope $scope, D\SourceVersionRef $version, D\SnapshotId $snapshot,
        D\Decimal $expectedLifecycleEpoch, D\Decimal $expectedPointerVersion): void;
}
