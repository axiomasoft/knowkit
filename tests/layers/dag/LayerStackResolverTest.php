<?php
declare(strict_types=1);

namespace KnowKit\Tests\Layers\Dag;

use KnowKit\Application\Layers\LayerStackResolver;
use KnowKit\Domain\{DomainFailure, Fingerprint, LocalId, Outcome, TenantId, Ulid};
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\{
    DeliveryMode,
    DisclosureMode,
    LayerBacking,
    LayerBudgetPolicy,
    LayerId,
    LayerNode,
    MergeStrategy,
    ResolutionTier,
    TrustTier,
};
use PHPUnit\Framework\TestCase;

final class LayerStackResolverTest extends TestCase
{
    private function budget(): LayerBudgetPolicy
    {
        return new LayerBudgetPolicy(
            minGuaranteedTokens: 128,
            maxCeilingTokens: 1024,
            maxBytes: 16384,
            weightBps: 5000,
            preferredTier: ResolutionTier::R1Digest,
            compactFallbackTier: ResolutionTier::R0Outline,
        );
    }

    /**
     * @param list<string> $deps
     * @param list<string> $lockedKeys
     * @param array<string, string|int|bool> $ruleValues
     * @param list<string> $denySelectors
     */
    private function node(
        string $id,
        array $deps,
        TrustTier $trust,
        DeliveryMode $delivery,
        MergeStrategy $merge,
        DisclosureMode $disclosure = DisclosureMode::VerbatimAllowed,
        array $lockedKeys = [],
        array $ruleValues = [],
        array $denySelectors = [],
        ?LayerBacking $backing = null,
    ): LayerNode {
        return new LayerNode(
            layerId: LayerId::fromString($id),
            dependsOnLayerIds: array_map(LayerId::fromString(...), $deps),
            trustTier: $trust,
            deliveryMode: $delivery,
            mergeStrategy: $merge,
            disclosureMode: $disclosure,
            lockedKeys: $lockedKeys,
            ruleValues: new JsonObject($ruleValues),
            denySelectors: array_map(static fn(string $s): LocalId => new LocalId($s), $denySelectors),
            budget: $this->budget(),
            backing: $backing ?? LayerBacking::overlay(new LocalId($id)),
        );
    }

    public function testResolvesCanonicalFiveLayerDagAndPreservesPrefixDigests(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000001');
        $mountId = new Ulid('01JSM000000000000000000001');
        $catTenantId = new TenantId('01JSCAT0000000000000000000');
        $digest = new Fingerprint(str_repeat('a', 64));

        $l0 = $this->node(
            'core.invariants',
            [],
            TrustTier::TrustedDirective,
            DeliveryMode::PinnedPrefix,
            MergeStrategy::ImmutableSeal,
            DisclosureMode::VerbatimAllowed,
            ['safety.no_harm', 'answer.must_cite'],
            ['safety.no_harm' => true, 'answer.must_cite' => true],
            [],
            LayerBacking::directive(new Ulid('01JSD000000000000000000000'), $digest),
        );
        $l1 = $this->node(
            'method.promoted_rules',
            ['core.invariants'],
            TrustTier::TrustedDirective,
            DeliveryMode::PinnedPrefix,
            MergeStrategy::GuardedExtend,
            DisclosureMode::VerbatimAllowed,
            [],
            ['method.strict' => true],
            [],
            LayerBacking::directive(new Ulid('01JSD000000000000000000001'), $digest),
        );
        $l2 = $this->node(
            'org.school_42_policy',
            ['core.invariants', 'method.promoted_rules'],
            TrustTier::TrustedDirective,
            DeliveryMode::SessionPinned,
            MergeStrategy::ExclusionDeny,
            DisclosureMode::VerbatimAllowed,
            [],
            [],
            ['source:legacy_2018'],
            LayerBacking::directive(new Ulid('01JSD000000000000000000002'), $digest),
        );
        $l3 = $this->node(
            'subject.physics_mounted',
            ['org.school_42_policy'],
            TrustTier::CuratedReference,
            DeliveryMode::HybridRetrievable,
            MergeStrategy::WeightedMix,
            DisclosureMode::DerivedOnly,
            [],
            [],
            [],
            LayerBacking::catalogMount($mountId, $catTenantId, $digest),
        );
        $l4A = $this->node(
            'session.learner_state',
            ['subject.physics_mounted'],
            TrustTier::DynamicOverlay,
            DeliveryMode::VolatileSuffix,
            MergeStrategy::OverrideAllowed,
            DisclosureMode::VerbatimAllowed,
            [],
            ['learner.grade' => 8],
        );

        $resolver = new LayerStackResolver();
        // Deliberately pass out of order to verify Kahn topological sort.
        $stackA = $resolver->resolve($tenantId, $stackId, [$l4A, $l2, $l0, $l3, $l1]);

        self::assertSame(
            ['core.invariants', 'method.promoted_rules', 'org.school_42_policy', 'subject.physics_mounted', 'session.learner_state'],
            array_map(static fn(LayerNode $n): string => $n->layerId->value, $stackA->nodes),
        );
        self::assertArrayHasKey('safety.no_harm', $stackA->lockedKeyOwners);
        self::assertArrayHasKey('answer.must_cite', $stackA->lockedKeyOwners);
        self::assertSame('core.invariants', $stackA->lockedKeyOwners['answer.must_cite']['layerId']->value);
        self::assertSame(['source:legacy_2018'], array_map(static fn(LocalId $id): string => $id->value, $stackA->effectiveDenySelectors));

        // Different L4 (learner_state) preserves both staticPrefixDigest and sessionPrefixDigest, changing only stackDigest.
        $l4B = $this->node(
            'session.learner_state',
            ['subject.physics_mounted'],
            TrustTier::DynamicOverlay,
            DeliveryMode::VolatileSuffix,
            MergeStrategy::OverrideAllowed,
            DisclosureMode::VerbatimAllowed,
            [],
            ['learner.grade' => 11],
        );
        $stackB = $resolver->resolve($tenantId, $stackId, [$l0, $l1, $l2, $l3, $l4B]);

        self::assertTrue($stackA->staticPrefixDigest->equals($stackB->staticPrefixDigest));
        self::assertTrue($stackA->sessionPrefixDigest->equals($stackB->sessionPrefixDigest));
        self::assertFalse($stackA->stackDigest->equals($stackB->stackDigest));

        // Different L2 (SessionPinned) preserves staticPrefixDigest, changing sessionPrefixDigest & stackDigest.
        $l2School99 = $this->node(
            'org.school_42_policy',
            ['core.invariants', 'method.promoted_rules'],
            TrustTier::TrustedDirective,
            DeliveryMode::SessionPinned,
            MergeStrategy::ExclusionDeny,
            DisclosureMode::VerbatimAllowed,
            [],
            [],
            ['source:legacy_2018', 'source:draft_2020'],
            LayerBacking::directive(new Ulid('01JSD000000000000000000002'), $digest),
        );
        $stackC = $resolver->resolve($tenantId, $stackId, [$l0, $l1, $l2School99, $l3, $l4A]);

        self::assertTrue($stackA->staticPrefixDigest->equals($stackC->staticPrefixDigest));
        self::assertFalse($stackA->sessionPrefixDigest->equals($stackC->sessionPrefixDigest));
        self::assertFalse($stackA->stackDigest->equals($stackC->stackDigest));
    }

    public function testKahnTieBreakUsesUtf16BeOrdering(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000002');
        $resolver = new LayerStackResolver();

        $b = $this->node('b.layer', [], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);
        $a = $this->node('a.layer', [], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);
        $c = $this->node('c.layer', ['a.layer', 'b.layer'], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);

        $stack = $resolver->resolve($tenantId, $stackId, [$c, $b, $a]);

        self::assertSame(
            ['a.layer', 'b.layer', 'c.layer'],
            array_map(static fn(LayerNode $n): string => $n->layerId->value, $stack->nodes),
        );
    }

    public function testRejectsImmutableSealOverrideEvenWithOverrideAllowed(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000003');
        $resolver = new LayerStackResolver();

        $l0 = $this->node(
            'core.invariants',
            [],
            TrustTier::TrustedDirective,
            DeliveryMode::PinnedPrefix,
            MergeStrategy::ImmutableSeal,
            DisclosureMode::VerbatimAllowed,
            ['answer.must_cite'],
            ['answer.must_cite' => true],
        );
        $l4 = $this->node(
            'session.learner_state',
            ['core.invariants'],
            TrustTier::DynamicOverlay,
            DeliveryMode::VolatileSuffix,
            MergeStrategy::OverrideAllowed,
            DisclosureMode::VerbatimAllowed,
            [],
            ['answer.must_cite' => false],
        );

        try {
            $resolver->resolve($tenantId, $stackId, [$l0, $l4]);
            self::fail('Expected DomainFailure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
            self::assertSame('invariant_violation', $e->errorCode);
        }
    }

    public function testRejectsInvalidTrustTierDeliveryMatrix(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000004');
        $resolver = new LayerStackResolver();

        $invalidDynamic = $this->node(
            'session.bad_overlay',
            [],
            TrustTier::DynamicOverlay,
            DeliveryMode::PinnedPrefix,
            MergeStrategy::OverrideAllowed,
        );

        try {
            $resolver->resolve($tenantId, $stackId, [$invalidDynamic]);
            self::fail('Expected DomainFailure for DynamicOverlay in PinnedPrefix');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
            self::assertSame('invalid_trust_tier', $e->errorCode);
        }

        $invalidUntrusted = $this->node(
            'evidence.bad_pdf',
            [],
            TrustTier::UntrustedEvidence,
            DeliveryMode::SessionPinned,
            MergeStrategy::WeightedMix,
        );

        try {
            $resolver->resolve($tenantId, $stackId, [$invalidUntrusted]);
            self::fail('Expected DomainFailure for UntrustedEvidence in SessionPinned');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
            self::assertSame('invalid_trust_tier', $e->errorCode);
        }
    }

    public function testRejectsLayerCycleDuplicateAndUnknownDependency(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $stackId = new Ulid('01JST000000000000000000005');
        $resolver = new LayerStackResolver();

        $n1 = $this->node('layer.one', ['layer.two'], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);
        $n2 = $this->node('layer.two', ['layer.one'], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);

        try {
            $resolver->resolve($tenantId, $stackId, [$n1, $n2]);
            self::fail('Expected cycle failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('layer_cycle', $e->errorCode);
        }

        $dup = $this->node('layer.one', [], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);
        try {
            $resolver->resolve($tenantId, $stackId, [$dup, $dup]);
            self::fail('Expected duplicate failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('duplicate_identifier', $e->errorCode);
        }

        $missing = $this->node('layer.one', ['layer.missing'], TrustTier::DynamicOverlay, DeliveryMode::VolatileSuffix, MergeStrategy::OverrideAllowed);
        try {
            $resolver->resolve($tenantId, $stackId, [$missing]);
            self::fail('Expected unknown dependency failure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Incompatible, $e->outcome);
            self::assertSame('unknown_layer_dependency', $e->errorCode);
        }
    }
}
