<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\{Fingerprint, LocalId, Sha256, TenantId, Ulid, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class ResolvedLayerStack
{
    /**
     * @param list<LayerNode> $nodes
     * @param array<string, array{layerId: LayerId, valueDigest: Sha256}> $lockedKeyOwners
     * @param list<LocalId> $effectiveDenySelectors
     */
    public function __construct(
        public TenantId $tenantId,
        public Ulid $stackId,
        public array $nodes,
        public array $lockedKeyOwners,
        public array $effectiveDenySelectors,
        public Fingerprint $staticPrefixDigest,
        public Fingerprint $sessionPrefixDigest,
        public Fingerprint $stackDigest,
    ) {
        ValueGuard::objects($nodes, LayerNode::class, 64);
        if (count($nodes) < 1) {
            throw new \InvalidArgumentException('ResolvedLayerStack requires at least 1 node');
        }
        if (count($lockedKeyOwners) > 256) {
            throw new \InvalidArgumentException('lockedKeyOwners exceeds 256 entries');
        }
        ValueGuard::objects($effectiveDenySelectors, LocalId::class, 1024);
    }

    public function toWire(): JsonObject
    {
        return self::wireWithoutStackDigest(
            $this->tenantId,
            $this->stackId,
            $this->nodes,
            $this->lockedKeyOwners,
            $this->effectiveDenySelectors,
            $this->staticPrefixDigest,
            $this->sessionPrefixDigest,
        )->with('stackDigest', $this->stackDigest->value);
    }

    /**
     * @param list<LayerNode> $nodes
     * @param array<string, array{layerId: LayerId, valueDigest: Sha256}> $lockedKeyOwners
     * @param list<LocalId> $effectiveDenySelectors
     */
    public static function wireWithoutStackDigest(
        TenantId $tenantId,
        Ulid $stackId,
        array $nodes,
        array $lockedKeyOwners,
        array $effectiveDenySelectors,
        Fingerprint $staticPrefixDigest,
        Fingerprint $sessionPrefixDigest,
    ): JsonObject {
        $ownersWire = [];
        foreach ($lockedKeyOwners as $key => $owner) {
            $ownersWire[$key] = new JsonObject([
                'layerId' => $owner['layerId']->value,
                'valueDigest' => $owner['valueDigest']->value,
            ]);
        }
        return new JsonObject([
            'schemaVersion' => 'layer-stack:1',
            'tenantId' => $tenantId->value,
            'stackId' => $stackId->value,
            'nodes' => array_map(static fn(LayerNode $node): JsonObject => $node->toWire(), $nodes),
            'lockedKeyOwners' => new JsonObject($ownersWire),
            'effectiveDenySelectors' => array_map(
                static fn(LocalId $id): string => $id->value,
                $effectiveDenySelectors,
            ),
            'staticPrefixDigest' => $staticPrefixDigest->value,
            'sessionPrefixDigest' => $sessionPrefixDigest->value,
        ]);
    }
}
