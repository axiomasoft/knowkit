<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{DecisionReceipt, Scope, Ulid};
use KnowKit\Domain\Layers\CatalogMountRef;

interface CatalogMountStore
{
    /**
     * Requires Operation::CatalogMount allow; rejects private_tenant or unsealed snapshot -> Denied('catalog_mount_forbidden').
     */
    public function mount(Scope $scope, CatalogMountRef $mount, DecisionReceipt $receipt): void;

    public function revokeMount(Scope $scope, Ulid $mountId): void;

    /** @return list<CatalogMountRef> */
    public function listActiveMounts(Ulid $tenantId): array;
}
