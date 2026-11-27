<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\{Fingerprint, SnapshotRef, TenantId, Ulid};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};

final readonly class CatalogMountRef
{
    public function __construct(
        public Ulid $mountId,
        public TenantId $tenantId,
        public TenantId $catalogTenantId,
        public SnapshotRef $snapshotRef,
        public DisclosureMode $maxDisclosureMode,
        public bool $sealed,
        public Fingerprint $mountGrantDigest,
        public bool $privateTenant = false,
    ) {
        if (!$this->snapshotRef->tenantId->equals($this->catalogTenantId)) {
            throw new \InvalidArgumentException('Snapshot tenant must match catalogTenantId');
        }
    }

    public static function grantDigest(
        Ulid $mountId,
        TenantId $tenantId,
        TenantId $catalogTenantId,
        SnapshotRef $snapshotRef,
        DisclosureMode $maxDisclosureMode,
    ): Fingerprint {
        return new Fingerprint(HashDomain::CatalogMount->digest(new JsonObject([
            'mountId' => $mountId->value,
            'tenantId' => $tenantId->value,
            'catalogTenantId' => $catalogTenantId->value,
            'snapshotRef' => $snapshotRef->toWire(),
            'maxDisclosureMode' => $maxDisclosureMode->value,
        ])));
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'mountId' => $this->mountId->value,
            'tenantId' => $this->tenantId->value,
            'catalogTenantId' => $this->catalogTenantId->value,
            'snapshotRef' => $this->snapshotRef->toWire(),
            'maxDisclosureMode' => $this->maxDisclosureMode->value,
            'sealed' => $this->sealed,
            'privateTenant' => $this->privateTenant,
            'mountGrantDigest' => $this->mountGrantDigest->value,
        ]);
    }
}
