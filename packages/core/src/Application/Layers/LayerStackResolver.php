<?php
declare(strict_types=1);

namespace KnowKit\Application\Layers;

use KnowKit\Domain\{DomainFailure, Fingerprint, LocalId, Outcome, Sha256, TenantId, Ulid, ValueGuard};
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Domain\Layers\{
    DeliveryMode,
    LayerId,
    LayerNode,
    MergeStrategy,
    ResolvedLayerStack,
};

final readonly class LayerStackResolver
{
    /**
     * @param list<LayerNode> $nodes
     */
    public function resolve(TenantId $tenantId, Ulid $stackId, array $nodes): ResolvedLayerStack
    {
        ValueGuard::objects($nodes, LayerNode::class, 64);
        if (count($nodes) < 1) {
            throw new DomainFailure(Outcome::Incompatible, 'empty_layer_stack');
        }

        /** @var array<string, LayerNode> $byId */
        $byId = [];
        foreach ($nodes as $node) {
            $id = $node->layerId->value;
            if (isset($byId[$id])) {
                throw new DomainFailure(Outcome::Incompatible, 'duplicate_identifier');
            }
            $byId[$id] = $node;
        }

        /** @var array<string, int> $inDegree */
        $inDegree = [];
        /** @var array<string, list<string>> $dependents */
        $dependents = [];
        foreach ($byId as $id => $node) {
            $inDegree[$id] = count($node->dependsOnLayerIds);
            $dependents[$id] = $dependents[$id] ?? [];
            foreach ($node->dependsOnLayerIds as $depId) {
                if (!isset($byId[$depId->value])) {
                    throw new DomainFailure(Outcome::Incompatible, 'unknown_layer_dependency');
                }
                $dependents[$depId->value][] = $id;
            }
            if (!$node->trustTier->allowsDeliveryMode($node->deliveryMode)) {
                throw new DomainFailure(Outcome::Denied, 'invalid_trust_tier');
            }
        }

        /** @var list<string> $ready */
        $ready = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $ready[] = $id;
            }
        }

        /** @var list<LayerNode> $sorted */
        $sorted = [];
        while ($ready !== []) {
            usort(
                $ready,
                static fn(string $left, string $right): int => strcmp(
                    $byId[$left]->layerId->utf16BeKey(),
                    $byId[$right]->layerId->utf16BeKey(),
                ),
            );
            $currentId = array_shift($ready);
            $current = $byId[$currentId];
            $sorted[] = $current;

            foreach ($dependents[$currentId] as $childId) {
                $inDegree[$childId]--;
                if ($inDegree[$childId] === 0) {
                    $ready[] = $childId;
                }
            }
        }

        if (count($sorted) !== count($nodes)) {
            throw new DomainFailure(Outcome::Incompatible, 'layer_cycle');
        }

        /** @var array<string, array{layerId: LayerId, valueDigest: Sha256, canonicalValue: string}> $lockedOwners */
        $lockedOwners = [];
        /** @var array<string, LocalId> $effectiveDeny */
        $effectiveDeny = [];

        foreach ($sorted as $node) {
            foreach ($lockedOwners as $key => $owner) {
                if ($node->ruleValues->has($key)) {
                    $candidate = Jcs::canonicalize($node->ruleValues->get($key));
                    if ($candidate !== $owner['canonicalValue']) {
                        throw new DomainFailure(Outcome::Denied, 'invariant_violation');
                    }
                } elseif (
                    $node->mergeStrategy === MergeStrategy::ImmutableSeal
                    && in_array($key, $node->lockedKeys, true)
                    && $owner['canonicalValue'] !== 'null'
                ) {
                    throw new DomainFailure(Outcome::Denied, 'invariant_violation');
                }
            }

            if ($node->mergeStrategy === MergeStrategy::ImmutableSeal) {
                foreach ($node->lockedKeys as $key) {
                    if (isset($lockedOwners[$key])) {
                        continue;
                    }
                    $canonical = $node->ruleValues->has($key)
                        ? Jcs::canonicalize($node->ruleValues->get($key))
                        : 'null';
                    $lockedOwners[$key] = [
                        'layerId' => $node->layerId,
                        'valueDigest' => new Sha256(hash('sha256', $canonical)),
                        'canonicalValue' => $canonical,
                    ];
                }
            }

            foreach ($node->denySelectors as $selector) {
                $effectiveDeny[$selector->value] = $selector;
            }
        }

        /** @var array<string, array{layerId: LayerId, valueDigest: Sha256}> $ownersForStack */
        $ownersForStack = [];
        foreach ($lockedOwners as $key => $owner) {
            $ownersForStack[$key] = [
                'layerId' => $owner['layerId'],
                'valueDigest' => $owner['valueDigest'],
            ];
        }
        $effectiveDenySelectors = array_values($effectiveDeny);

        /** @var list<JsonObject> $pinnedPrefixNodes */
        $pinnedPrefixNodes = [];
        /** @var list<JsonObject> $sessionPinnedNodes */
        $sessionPinnedNodes = [];
        foreach ($sorted as $node) {
            if ($node->deliveryMode === DeliveryMode::PinnedPrefix) {
                $pinnedPrefixNodes[] = $node->toWire();
            } elseif ($node->deliveryMode === DeliveryMode::SessionPinned) {
                $sessionPinnedNodes[] = $node->toWire();
            }
        }

        $staticPrefixDigest = new Fingerprint(HashDomain::LayerPrefix->digest($pinnedPrefixNodes));
        $sessionPrefixDigest = new Fingerprint(
            HashDomain::LayerSession->digest([...$pinnedPrefixNodes, ...$sessionPinnedNodes]),
        );
        $stackDigest = new Fingerprint(HashDomain::LayerStack->digest(
            ResolvedLayerStack::wireWithoutStackDigest(
                $tenantId,
                $stackId,
                $sorted,
                $ownersForStack,
                $effectiveDenySelectors,
                $staticPrefixDigest,
                $sessionPrefixDigest,
            ),
        ));

        return new ResolvedLayerStack(
            tenantId: $tenantId,
            stackId: $stackId,
            nodes: $sorted,
            lockedKeyOwners: $ownersForStack,
            effectiveDenySelectors: $effectiveDenySelectors,
            staticPrefixDigest: $staticPrefixDigest,
            sessionPrefixDigest: $sessionPrefixDigest,
            stackDigest: $stackDigest,
        );
    }
}
