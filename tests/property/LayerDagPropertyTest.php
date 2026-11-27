<?php

declare(strict_types=1);

namespace KnowKit\Tests\Property;

use KnowKit\Application\Layers\LayerStackResolver;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Layers\DeliveryMode;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\LayerBacking;
use KnowKit\Domain\Layers\LayerBudgetPolicy;
use KnowKit\Domain\Layers\LayerId;
use KnowKit\Domain\Layers\LayerNode;
use KnowKit\Domain\Layers\MergeStrategy;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Layers\TrustTier;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\Ulid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[Group('property:invariants')]
final class LayerDagPropertyTest extends TestCase
{
    private function seed(): int
    {
        $env = getenv('KNOWKIT_FUZZ_SEED');

        return ($env !== false && ctype_digit($env)) ? (int) $env : 20261127;
    }

    public function testRandomDagsArePermutationInvariantAndEnforceDenyMonotonicity(): void
    {
        $rng = new Randomizer(new Mt19937($this->seed()));
        $resolver = new LayerStackResolver();
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000001');
        $digest = new Fingerprint(str_repeat('a', 64));
        $budget = new LayerBudgetPolicy(64, 1024, 16384, 5000, ResolutionTier::R1Digest, ResolutionTier::R0Outline);

        $dagCount = 100;
        $permsPerDag = 20;

        for ($d = 0; $d < $dagCount; $d++) {
            $nodeCount = $rng->getInt(1, 24);
            $nodes = [];
            $ids = [];
            for ($i = 0; $i < $nodeCount; $i++) {
                $prefix = ($i % 3 === 0) ? 'layer_' : 'node.';
                $ids[] = $prefix . $d . '_' . $i;
            }

            for ($i = 0; $i < $nodeCount; $i++) {
                $deps = [];
                if ($i > 0) {
                    $maxDeps = min($i, 3);
                    $depCount = $rng->getInt(0, $maxDeps);
                    if ($depCount > 0) {
                        $candidates = range(0, $i - 1);
                        $picked = $rng->pickArrayKeys($candidates, $depCount);
                        foreach ($picked as $idx) {
                            $deps[] = $ids[$candidates[$idx]];
                        }
                    }
                }
                $isDirective = ($i === 0);
                $nodes[] = new LayerNode(
                    layerId: LayerId::fromString($ids[$i]),
                    dependsOnLayerIds: array_map(LayerId::fromString(...), $deps),
                    trustTier: $isDirective ? TrustTier::TrustedDirective : TrustTier::CuratedReference,
                    deliveryMode: $isDirective ? DeliveryMode::PinnedPrefix : DeliveryMode::SessionPinned,
                    mergeStrategy: $isDirective ? MergeStrategy::ImmutableSeal : MergeStrategy::GuardedExtend,
                    disclosureMode: DisclosureMode::VerbatimAllowed,
                    lockedKeys: $isDirective ? ['locked.root_' . $d] : [],
                    ruleValues: new JsonObject($isDirective ? ['locked.root_' . $d => true] : ['k_' . $i => $i]),
                    denySelectors: [new LocalId('deny_' . $i)],
                    budget: $budget,
                    backing: $isDirective
                        ? LayerBacking::directive(new Ulid('01JSD000000000000000000000'), $digest)
                        : LayerBacking::overlay(new LocalId('ov_' . $i)),
                );
            }

            $baseline = $resolver->resolve($tenantId, $stackId, $nodes);
            $baseOrder = array_map(static fn (LayerNode $n): string => $n->layerId->value, $baseline->nodes);

            // Monotonicity: every node's denySelectors must be present in effectiveDenySelectors.
            $effectiveDeny = array_map(static fn (LocalId $id): string => $id->value, $baseline->effectiveDenySelectors);
            foreach ($nodes as $n) {
                foreach ($n->denySelectors as $ds) {
                    self::assertContains($ds->value, $effectiveDeny);
                }
            }

            for ($p = 0; $p < $permsPerDag; $p++) {
                $shuffled = $rng->shuffleArray($nodes);
                $resolved = $resolver->resolve($tenantId, $stackId, $shuffled);
                $order = array_map(static fn (LayerNode $n): string => $n->layerId->value, $resolved->nodes);
                self::assertSame($baseOrder, $order);
                self::assertSame($baseline->staticPrefixDigest->value, $resolved->staticPrefixDigest->value);
                self::assertSame($baseline->sessionPrefixDigest->value, $resolved->sessionPrefixDigest->value);
                self::assertSame($baseline->stackDigest->value, $resolved->stackDigest->value);
            }
        }
    }

    public function testCycleInjectionAndLockedKeyMutationAlwaysFailClosed(): void
    {
        $rng = new Randomizer(new Mt19937($this->seed() + 1));
        $resolver = new LayerStackResolver();
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000002');
        $digest = new Fingerprint(str_repeat('b', 64));
        $budget = new LayerBudgetPolicy(64, 1024, 16384, 5000, ResolutionTier::R1Digest, ResolutionTier::R0Outline);

        for ($i = 0; $i < 100; $i++) {
            $count = $rng->getInt(2, 12);
            $nodes = [];
            for ($k = 0; $k < $count; $k++) {
                $deps = $k > 0 ? ['n_' . ($k - 1)] : [];
                $nodes[] = new LayerNode(
                    layerId: LayerId::fromString('n_' . $k),
                    dependsOnLayerIds: array_map(LayerId::fromString(...), $deps),
                    trustTier: $k === 0 ? TrustTier::TrustedDirective : TrustTier::DynamicOverlay,
                    deliveryMode: $k === 0 ? DeliveryMode::PinnedPrefix : DeliveryMode::VolatileSuffix,
                    mergeStrategy: $k === 0 ? MergeStrategy::ImmutableSeal : MergeStrategy::OverrideAllowed,
                    disclosureMode: DisclosureMode::VerbatimAllowed,
                    lockedKeys: $k === 0 ? ['locked.key'] : [],
                    ruleValues: new JsonObject($k === 0 ? ['locked.key' => 'immutable'] : ['other' => $k]),
                    denySelectors: [],
                    budget: $budget,
                    backing: $k === 0
                        ? LayerBacking::directive(new Ulid('01JSD000000000000000000000'), $digest)
                        : LayerBacking::overlay(new LocalId('ov_' . $k)),
                );
            }

            // 1) Inject back-edge into node 0 from random node j > 0 -> layer_cycle.
            $backIdx = $rng->getInt(1, $count - 1);
            $cyclic = $nodes;
            $cyclic[0] = new LayerNode(
                layerId: $nodes[0]->layerId,
                dependsOnLayerIds: [LayerId::fromString('n_' . $backIdx)],
                trustTier: $nodes[0]->trustTier,
                deliveryMode: $nodes[0]->deliveryMode,
                mergeStrategy: $nodes[0]->mergeStrategy,
                disclosureMode: $nodes[0]->disclosureMode,
                lockedKeys: $nodes[0]->lockedKeys,
                ruleValues: $nodes[0]->ruleValues,
                denySelectors: [],
                budget: $budget,
                backing: $nodes[0]->backing,
            );
            try {
                $resolver->resolve($tenantId, $stackId, $cyclic);
                self::fail('Expected layer_cycle');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Incompatible, $e->outcome);
                self::assertSame('layer_cycle', $e->errorCode);
            }

            // 2) Mutate locked.key in random descendant even with OverrideAllowed -> invariant_violation.
            $mutIdx = $rng->getInt(1, $count - 1);
            $mutated = $nodes;
            $mutated[$mutIdx] = new LayerNode(
                layerId: $nodes[$mutIdx]->layerId,
                dependsOnLayerIds: $nodes[$mutIdx]->dependsOnLayerIds,
                trustTier: $nodes[$mutIdx]->trustTier,
                deliveryMode: $nodes[$mutIdx]->deliveryMode,
                mergeStrategy: MergeStrategy::OverrideAllowed,
                disclosureMode: $nodes[$mutIdx]->disclosureMode,
                lockedKeys: [],
                ruleValues: new JsonObject(['locked.key' => 'tampered_' . $i]),
                denySelectors: [],
                budget: $budget,
                backing: $nodes[$mutIdx]->backing,
            );
            try {
                $resolver->resolve($tenantId, $stackId, $mutated);
                self::fail('Expected invariant_violation');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Denied, $e->outcome);
                self::assertSame('invariant_violation', $e->errorCode);
            }
        }
    }
}
