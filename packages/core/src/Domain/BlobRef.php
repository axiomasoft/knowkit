<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class BlobRef
{
    public function __construct(public TenantId $tenantId, public SourceId $sourceId, public Sha256 $digest,
        public Uint64 $byteLength, public string $storageId, public string $objectVersion, public string $ownerKind)
    {
        ValueGuard::text($storageId, 128); ValueGuard::text($objectVersion, 256);
        if (!in_array($ownerKind, ['owned', 'external'], true)) { throw new \InvalidArgumentException('Owner kind required'); }
    }
}
