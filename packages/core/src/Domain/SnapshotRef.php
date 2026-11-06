<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class SnapshotRef
{
    public function __construct(public TenantId $tenantId, public KbId $kbId,
        public SnapshotId $snapshotId, public Fingerprint $manifestDigest) {}
    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId' => $this->tenantId->value, 'kbId' => $this->kbId->value,
            'snapshotId' => $this->snapshotId->value, 'manifestDigest' => $this->manifestDigest->value]);
    }
}
