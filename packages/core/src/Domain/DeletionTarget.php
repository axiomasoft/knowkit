<?php
declare(strict_types=1);
namespace KnowKit\Domain;

/** Trusted ledger coordinates, never a caller-supplied provider input or read authority. */
final readonly class DeletionTarget
{
    public function __construct(
        public TenantId $tenantId,
        public SourceId $sourceId,
        public DeletionTaskId $taskId,
        public ResourceId $resourceId,
        public string $storageId,
        public ?string $storageKey,
        public ?string $objectVersion,
        public ?string $stagingId,
        public string $resourceClass,
    ) {}

    public function resource(): ResourceRef { return new ResourceRef($this->tenantId, 'resource', $this->resourceId); }
}
