<?php
declare(strict_types=1);

namespace KnowKit\Tests\Layers\Mounts;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Layers\PdoLayerStore;
use KnowKit\Application\Layers\LayerStackResolver;
use KnowKit\Domain\{
    ActorId,
    DomainFailure,
    KbId,
    LocalId,
    Outcome,
    Sha256,
    SnapshotId,
    SnapshotRef,
    SourceDigest,
    SourceId,
    SourceVersionId,
    SourceVersionRef,
    TenantId,
    Ulid,
    UtcInstant,
};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Domain\Layers\{
    DeliveryMode,
    DirectiveArtifact,
    DisclosureMode,
    LayerBacking,
    LayerBudgetPolicy,
    LayerId,
    LayerNode,
    MergeStrategy,
    ResolutionTier,
    TrustTier,
};
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('layers:mounts')]
final class PdoLayerStoreTest extends TestCase
{
    public function testSavesAndFindsDirectiveArtifactAndEnforcesImmutability(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->tenant(2);
            $store = new PdoLayerStore($fx->db, $fx->tx, $fx->clock, $fx->ids);

            $tenant1 = new TenantId(CatalogFixture::id(1));
            $tenant2 = new TenantId(CatalogFixture::id(2));
            $directiveId = new Ulid(CatalogFixture::id(501));
            $body = "# Methodological Directive\nAlways cite page numbers.";
            $derivedFrom = [
                new SourceVersionRef(
                    $tenant1,
                    new SourceId(CatalogFixture::id(2)),
                    new SourceVersionId(CatalogFixture::id(3)),
                    new SourceDigest(str_repeat('b', 64)),
                ),
            ];
            $artifact = new DirectiveArtifact(
                tenantId: $tenant1,
                directiveId: $directiveId,
                version: 1,
                title: 'Citation Policy',
                contentFormat: 'compact_markdown',
                body: $body,
                bodyDigest: new Sha256(hash('sha256', $body)),
                lockedRules: new JsonObject(['answer.must_cite' => true, 'safety.no_harm' => true]),
                promotedBy: new ActorId(CatalogFixture::id(101)),
                promotedAt: new UtcInstant('2026-11-06T12:00:01Z'),
                derivedFrom: $derivedFrom,
                approvalDigest: new Sha256(str_repeat('c', 64)),
            );

            $store->saveDirective($artifact);
            $store->saveDirective($artifact); // idempotent replay

            $loaded = $store->findDirective($tenant1, $directiveId);
            self::assertNotNull($loaded);
            self::assertSame($artifact->artifactDigest()->value, $loaded->artifactDigest()->value);
            self::assertSame('Citation Policy', $loaded->title);
            self::assertTrue($loaded->lockedRules->get('answer.must_cite'));
            self::assertCount(1, $loaded->derivedFrom);
            self::assertNull($store->findDirective($tenant2, $directiveId));

            $tampered = new DirectiveArtifact(
                tenantId: $tenant1,
                directiveId: $directiveId,
                version: 1,
                title: 'Citation Policy',
                contentFormat: 'compact_markdown',
                body: 'Tampered body',
                bodyDigest: new Sha256(hash('sha256', 'Tampered body')),
                lockedRules: new JsonObject(['answer.must_cite' => true]),
                promotedBy: new ActorId(CatalogFixture::id(101)),
                promotedAt: new UtcInstant('2026-11-06T12:00:01Z'),
                derivedFrom: $derivedFrom,
                approvalDigest: new Sha256(str_repeat('c', 64)),
            );

            $this->expectException(DomainFailure::class);
            try {
                $store->saveDirective($tampered);
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Incompatible, $e->outcome);
                self::assertSame('immutable_conflict', $e->errorCode);
                throw $e;
            }
        });
    }

    public function testSavesLayerNodesAndRejectsInvalidTrustTierDeliveryMatrixInSql(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $store = new PdoLayerStore($fx->db, $fx->tx, $fx->clock, $fx->ids);
            $tenant1 = new TenantId(CatalogFixture::id(1));

            $budget = new LayerBudgetPolicy(100, 1000, 32768, 5000, ResolutionTier::R1Digest, ResolutionTier::R0Outline);
            $validNode = new LayerNode(
                layerId: new LayerId('core.invariants'),
                dependsOnLayerIds: [],
                trustTier: TrustTier::TrustedDirective,
                deliveryMode: DeliveryMode::PinnedPrefix,
                mergeStrategy: MergeStrategy::ImmutableSeal,
                disclosureMode: DisclosureMode::VerbatimAllowed,
                lockedKeys: ['safety.no_harm'],
                ruleValues: new JsonObject(['safety.no_harm' => true]),
                denySelectors: [new LocalId('source:legacy_2018')],
                budget: $budget,
                backing: LayerBacking::directive(new Ulid(CatalogFixture::id(501)), new Sha256(str_repeat('d', 64))),
            );

            $store->saveLayerNode($tenant1, $validNode);
            $store->saveLayerNode($tenant1, $validNode); // idempotent on node_digest

            $count = (int) $fx->db->execute(
                'SELECT count(*) FROM ' . $fx->db->table('layer_definitions') . ' WHERE tenant_id = ?',
                [$tenant1->value],
            )->fetchColumn();
            self::assertSame(1, $count);

            $invalidNode = new LayerNode(
                layerId: new LayerId('untrusted.pdf'),
                dependsOnLayerIds: [],
                trustTier: TrustTier::UntrustedEvidence,
                deliveryMode: DeliveryMode::PinnedPrefix,
                mergeStrategy: MergeStrategy::WeightedMix,
                disclosureMode: DisclosureMode::DerivedOnly,
                lockedKeys: [],
                ruleValues: new JsonObject([]),
                denySelectors: [],
                budget: $budget,
                backing: LayerBacking::overlay(new LocalId('user-upload')),
            );

            $this->expectException(DomainFailure::class);
            try {
                $store->saveLayerNode($tenant1, $invalidNode);
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Incompatible, $e->outcome);
                self::assertSame('storage_conflict', $e->errorCode);
                throw $e;
            }
        });
    }

    public function testSavesAndFindsResolvedLayerStackWithTenantIsolation(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->tenant(2);
            $store = new PdoLayerStore($fx->db, $fx->tx, $fx->clock, $fx->ids);

            $tenant1 = new TenantId(CatalogFixture::id(1));
            $tenant2 = new TenantId(CatalogFixture::id(2));
            $budget = new LayerBudgetPolicy(64, 512, 16384, 2500, ResolutionTier::R2Propositions, ResolutionTier::R1Digest);

            $l0 = new LayerNode(
                layerId: new LayerId('core.invariants'),
                dependsOnLayerIds: [],
                trustTier: TrustTier::TrustedDirective,
                deliveryMode: DeliveryMode::PinnedPrefix,
                mergeStrategy: MergeStrategy::ImmutableSeal,
                disclosureMode: DisclosureMode::VerbatimAllowed,
                lockedKeys: ['safety.no_harm'],
                ruleValues: new JsonObject(['safety.no_harm' => true]),
                denySelectors: [],
                budget: $budget,
                backing: LayerBacking::directive(new Ulid(CatalogFixture::id(501)), new Sha256(str_repeat('a', 64))),
            );
            $l1 = new LayerNode(
                layerId: new LayerId('org.school_42_policy'),
                dependsOnLayerIds: [new LayerId('core.invariants')],
                trustTier: TrustTier::TrustedDirective,
                deliveryMode: DeliveryMode::SessionPinned,
                mergeStrategy: MergeStrategy::ExclusionDeny,
                disclosureMode: DisclosureMode::VerbatimAllowed,
                lockedKeys: [],
                ruleValues: new JsonObject([]),
                denySelectors: [new LocalId('source:legacy_2018')],
                budget: $budget,
                backing: LayerBacking::snapshot(new SnapshotRef(
                    $tenant1,
                    new KbId(CatalogFixture::id(100)),
                    new SnapshotId(CatalogFixture::id(200)),
                    new \KnowKit\Domain\Fingerprint(str_repeat('e', 64)),
                )),
            );
            $l2 = new LayerNode(
                layerId: new LayerId('session.learner_state'),
                dependsOnLayerIds: [new LayerId('org.school_42_policy')],
                trustTier: TrustTier::DynamicOverlay,
                deliveryMode: DeliveryMode::VolatileSuffix,
                mergeStrategy: MergeStrategy::OverrideAllowed,
                disclosureMode: DisclosureMode::VerbatimAllowed,
                lockedKeys: [],
                ruleValues: new JsonObject(['session.level' => 3]),
                denySelectors: [],
                budget: $budget,
                backing: LayerBacking::overlay(new LocalId('learner-42')),
            );

            $stackId = new Ulid(CatalogFixture::id(701));
            $resolved = (new LayerStackResolver())->resolve($tenant1, $stackId, [$l2, $l0, $l1]);

            $store->saveResolvedStack($resolved);
            $store->saveResolvedStack($resolved); // idempotent

            $loaded = $store->findResolvedStack($tenant1, $stackId);
            self::assertNotNull($loaded);
            self::assertSame($resolved->stackDigest->value, $loaded->stackDigest->value);
            self::assertSame($resolved->staticPrefixDigest->value, $loaded->staticPrefixDigest->value);
            self::assertSame($resolved->sessionPrefixDigest->value, $loaded->sessionPrefixDigest->value);
            self::assertCount(3, $loaded->nodes);
            self::assertArrayHasKey('safety.no_harm', $loaded->lockedKeyOwners);
            self::assertSame('core.invariants', $loaded->lockedKeyOwners['safety.no_harm']['layerId']->value);
            self::assertCount(1, $loaded->effectiveDenySelectors);
            self::assertSame('source:legacy_2018', $loaded->effectiveDenySelectors[0]->value);

            self::assertNull($store->findResolvedStack($tenant2, $stackId));

            // Different stackDigest with same stackId must fail with immutable_conflict
            $otherResolved = (new LayerStackResolver())->resolve($tenant1, $stackId, [$l0]);
            $this->expectException(DomainFailure::class);
            try {
                $store->saveResolvedStack($otherResolved);
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Incompatible, $e->outcome);
                self::assertSame('immutable_conflict', $e->errorCode);
                throw $e;
            }
        });
    }
}
