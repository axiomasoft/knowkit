<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\{LocalId, Sha256, SnapshotRef, TenantId, Ulid, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class LayerBacking
{
    /**
     * @param list<LocalId>|null $serverIds
     */
    private function __construct(
        public string $kind,
        public ?Ulid $directiveId = null,
        public ?Sha256 $directiveDigest = null,
        public ?SnapshotRef $snapshotRef = null,
        public ?Ulid $catalogMountId = null,
        public ?TenantId $catalogTenantId = null,
        public ?Sha256 $mountGrantDigest = null,
        public ?array $serverIds = null,
        public ?LocalId $overlayKey = null,
    ) {
    }

    public static function directive(Ulid $directiveId, Sha256 $directiveDigest): self
    {
        return new self('directive', directiveId: $directiveId, directiveDigest: $directiveDigest);
    }

    public static function snapshot(SnapshotRef $snapshotRef): self
    {
        return new self('snapshot', snapshotRef: $snapshotRef);
    }

    public static function catalogMount(Ulid $catalogMountId, TenantId $catalogTenantId, Sha256 $mountGrantDigest): self
    {
        return new self(
            'catalog_mount',
            catalogMountId: $catalogMountId,
            catalogTenantId: $catalogTenantId,
            mountGrantDigest: $mountGrantDigest,
        );
    }

    /** @param list<LocalId> $serverIds */
    public static function toolCatalog(array $serverIds): self
    {
        ValueGuard::objects($serverIds, LocalId::class, 32);
        return new self('tool_catalog', serverIds: $serverIds);
    }

    public static function overlay(LocalId $overlayKey): self
    {
        return new self('overlay', overlayKey: $overlayKey);
    }

    public function toWire(): JsonObject
    {
        return match ($this->kind) {
            'directive' => new JsonObject([
                'kind' => 'directive',
                'directiveId' => $this->directiveId?->value,
                'directiveDigest' => $this->directiveDigest?->value,
            ]),
            'snapshot' => new JsonObject([
                'kind' => 'snapshot',
                'snapshotRef' => $this->snapshotRef?->toWire(),
            ]),
            'catalog_mount' => new JsonObject([
                'kind' => 'catalog_mount',
                'catalogMountId' => $this->catalogMountId?->value,
                'catalogTenantId' => $this->catalogTenantId?->value,
                'mountGrantDigest' => $this->mountGrantDigest?->value,
            ]),
            'tool_catalog' => new JsonObject([
                'kind' => 'tool_catalog',
                'serverIds' => array_map(
                    static fn(LocalId $id): string => $id->value,
                    $this->serverIds ?? [],
                ),
            ]),
            'overlay' => new JsonObject([
                'kind' => 'overlay',
                'overlayKey' => $this->overlayKey?->value,
            ]),
            default => throw new \LogicException('Unknown LayerBacking kind'),
        };
    }
}
