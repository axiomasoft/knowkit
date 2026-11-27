<?php
declare(strict_types=1);

namespace KnowKit\Tests\Layers\Dag;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Dependencies\{NodeMetadata, PdoDependencyStore};
use KnowKit\Adapter\Postgres\Layers\PdoLayerStore;
use KnowKit\Application\Layers\{DirectivePromotionService, PromoteDirective};
use KnowKit\Domain\{
    ActorId,
    Decision,
    DecisionReceipt,
    Decimal,
    DomainFailure,
    Fingerprint,
    KbId,
    LifecycleEvent,
    LockMode,
    MutationContext,
    Node,
    NodeRefs,
    Operation,
    Outcome,
    Parents,
    QueryGrant,
    ResourceRef,
    Scope,
    Sha256,
    SourceDigest,
    SourceId,
    SourceVersionId,
    SourceVersionRef,
    TenantId,
    Ulid,
    Usability,
    UsabilityReport,
    Usage,
    UtcInstant,
};
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\Layers\{DirectiveArtifact, LayerNode, ResolvedLayerStack};
use KnowKit\Port\{AccessPolicy, Clock, DependencyStore, LayerStore};
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('layers:dag')]
final class DirectivePromotionTest extends TestCase
{
    private function validator(): ContractValidator
    {
        static $validator = null;
        if ($validator !== null) {
            return $validator;
        }
        $root = dirname(__DIR__, 3);
        $schemas = [];
        foreach (['common', 'decision-receipt', 'mutation-context'] as $name) {
            $doc = StrictJson::decode((string) file_get_contents($root . '/schemas/' . $name . '.schema.json'), 1048576);
            $schemas[(string) $doc->get('$id')] = $doc;
        }
        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    private function receipt(
        Scope $scope,
        ResourceRef $resource,
        Operation $operation,
        string $decision,
        UtcInstant $now,
    ): DecisionReceipt {
        $wire = new JsonObject([
            'decision' => $decision,
            'operation' => $operation->value,
            'resourceRefs' => [$resource->toWire()],
            'binding' => new JsonObject([
                'tenantPolicyEpoch' => '1',
                'kbPolicyEpoch' => $scope->policyEpoch->value,
                'sources' => [],
            ]),
            'ownerRevision' => null,
            'checkedAt' => $now->value,
            'expiresAt' => $now->plusSeconds(30)->value,
            'hostScopeHash' => $scope->hostScopeHash->value,
        ]);
        return new DecisionReceipt(
            $wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)),
            $this->validator(),
        );
    }

    public function testPromotesDirectiveAndRegistersDependencyEdges(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $kbId = new KbId('01JSKB00000000000000000001');
        $actorId = new ActorId('01JSAC00000000000000000001');
        $hostScopeHash = new Sha256(str_repeat('1', 64));
        $scope = new Scope($tenantId, $kbId, $actorId, 'directive.promote', new Decimal('1'), $hostScopeHash);
        $now = new UtcInstant('2026-11-27T10:00:00Z');

        $sourceRef = new SourceVersionRef(
            $tenantId,
            new SourceId('01JSSR00000000000000000001'),
            new SourceVersionId('01JSSV00000000000000000001'),
            new SourceDigest(str_repeat('2', 64)),
        );

        $layerStore = new class implements LayerStore {
            public ?DirectiveArtifact $saved = null;
            public function saveDirective(DirectiveArtifact $artifact): void { $this->saved = $artifact; }
            public function findDirective(Ulid $tenantId, Ulid $directiveId): ?DirectiveArtifact { return $this->saved; }
            public function saveLayerNode(Ulid $tenantId, LayerNode $node): void {}
            public function saveResolvedStack(ResolvedLayerStack $stack): void {}
            public function findResolvedStack(Ulid $tenantId, Ulid $stackId): ?ResolvedLayerStack { return null; }
        };

        $depStore = new class implements DependencyStore {
            public ?Node $node = null;
            public ?Parents $parents = null;
            public function register(Node $node, Parents $parents): void
            {
                $this->node = $node;
                $this->parents = $parents;
            }
            public function checkAncestors(NodeRefs $refs): UsabilityReport
            {
                return new UsabilityReport(Decision::Allow, new NodeRefs($refs->tenantId, []));
            }
            public function recordUsage(Usage $usage): void {}
            public function invalidate(LifecycleEvent $event): void {}
        };

        $makeReceipt = fn(Scope $s, ResourceRef $r, Operation $o): DecisionReceipt
            => $this->receipt($s, $r, $o, 'Allow', $now);

        $accessPolicy = new class($makeReceipt) implements AccessPolicy {
            public function __construct(private readonly \Closure $factory) {}
            public function authorizeQuery(Scope $scope): QueryGrant
            {
                throw new \LogicException('Unused');
            }
            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
            {
                return ($this->factory)($scope, $resource, $operation);
            }
        };

        $clock = new class($now) implements Clock {
            public function __construct(private readonly UtcInstant $now) {}
            public function now(): UtcInstant { return $this->now; }
        };

        $service = new DirectivePromotionService($accessPolicy, $layerStore, $depStore, $clock);
        $command = new PromoteDirective(
            directiveId: new Ulid('01JSD000000000000000000010'),
            version: 1,
            title: 'Physics Method Directive',
            contentFormat: 'compact_markdown',
            body: 'Always cite the exact theorem.',
            lockedRules: new JsonObject(['answer.must_cite' => true]),
            derivedFrom: [$sourceRef],
            approvalDigest: new Sha256(str_repeat('3', 64)),
        );
        $mutation = new MutationContext(new JsonObject([
            'idempotencyKey' => 'promote-directive-1',
            'requestId' => '01JSRQ00000000000000000001',
            'expectedEpoch' => '1',
        ]), $this->validator());

        $artifact = $service->promote($scope, $command, $mutation);

        self::assertNotNull($layerStore->saved);
        self::assertTrue($artifact->directiveId->equals($command->directiveId));
        self::assertNotNull($depStore->node);
        self::assertNotNull($depStore->parents);
        self::assertCount(1, $depStore->parents->refs);
        self::assertSame('source_version', $depStore->parents->refs[0]->kind);
        self::assertTrue($depStore->parents->refs[0]->id->equals($sourceRef->sourceVersionId));
    }

    public function testDeniesPromotionWhenAccessPolicyDenies(): void
    {
        $tenantId = new TenantId('01JSC420000000000000000000');
        $kbId = new KbId('01JSKB00000000000000000001');
        $actorId = new ActorId('01JSAC00000000000000000001');
        $scope = new Scope($tenantId, $kbId, $actorId, 'directive.promote', new Decimal('1'), new Sha256(str_repeat('1', 64)));
        $now = new UtcInstant('2026-11-27T10:00:00Z');

        $layerStore = new class implements LayerStore {
            public function saveDirective(DirectiveArtifact $artifact): void {}
            public function findDirective(Ulid $tenantId, Ulid $directiveId): ?DirectiveArtifact { return null; }
            public function saveLayerNode(Ulid $tenantId, LayerNode $node): void {}
            public function saveResolvedStack(ResolvedLayerStack $stack): void {}
            public function findResolvedStack(Ulid $tenantId, Ulid $stackId): ?ResolvedLayerStack { return null; }
        };
        $depStore = new class implements DependencyStore {
            public function register(Node $node, Parents $parents): void {}
            public function checkAncestors(NodeRefs $refs): UsabilityReport { return new UsabilityReport(Decision::Allow, new NodeRefs($refs->tenantId, [])); }
            public function recordUsage(Usage $usage): void {}
            public function invalidate(LifecycleEvent $event): void {}
        };
        $makeReceipt = fn(Scope $s, ResourceRef $r, Operation $o): DecisionReceipt
            => $this->receipt($s, $r, $o, 'Deny', $now);
        $accessPolicy = new class($makeReceipt) implements AccessPolicy {
            public function __construct(private readonly \Closure $factory) {}
            public function authorizeQuery(Scope $scope): QueryGrant { throw new \LogicException('Unused'); }
            public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
            {
                return ($this->factory)($scope, $resource, $operation);
            }
        };
        $clock = new class($now) implements Clock {
            public function __construct(private readonly UtcInstant $now) {}
            public function now(): UtcInstant { return $this->now; }
        };

        $service = new DirectivePromotionService($accessPolicy, $layerStore, $depStore, $clock);
        $command = new PromoteDirective(
            directiveId: new Ulid('01JSD000000000000000000011'),
            version: 1,
            title: 'Denied Directive',
            contentFormat: 'compact_markdown',
            body: 'Denied body',
            lockedRules: new JsonObject([]),
            derivedFrom: [],
            approvalDigest: new Sha256(str_repeat('4', 64)),
        );
        $mutation = new MutationContext(new JsonObject([
            'idempotencyKey' => 'promote-directive-2',
            'requestId' => '01JSRQ00000000000000000002',
        ]), $this->validator());

        try {
            $service->promote($scope, $command, $mutation);
            self::fail('Expected DomainFailure');
        } catch (DomainFailure $e) {
            self::assertSame(Outcome::Denied, $e->outcome);
            self::assertSame('policy_denied', $e->errorCode);
        }
    }

    public function testPgPromotionRegistersLineageAndInvalidateSynchronouslyDeniesCheckAncestors(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $fx->tenant(1);
            $fx->kb(1);
            $fx->source(2, 1);

            $tenantId = new TenantId(CatalogFixture::id(1));
            $sourceId = new SourceId(CatalogFixture::id(2));
            $versionId = new SourceVersionId(CatalogFixture::id(3));
            $sourceDigest = new SourceDigest(hash('sha256', 'version-3'));

            $fx->resource(3003, 2, 1, 'owned', $sourceDigest->value);
            $fx->db->execute(
                "INSERT INTO {$fx->db->table('source_versions')} VALUES (?,?,?,?,?,15,'text/plain','synthetic',?,'2026-11-06T12:00:00Z')",
                [$tenantId->value, $versionId->value, $sourceId->value, CatalogFixture::id(3003), $sourceDigest->value, hash('sha256', 'version-request-3')],
            );
            $fx->db->execute(
                "INSERT INTO {$fx->db->table('source_version_state')} VALUES (?,?,'usable',NULL,NULL)",
                [$tenantId->value, $versionId->value],
            );

            $depStore = new PdoDependencyStore($fx->db, $fx->tx, $tenantId, $fx->ids);
            $layerStore = new PdoLayerStore($fx->db, $fx->tx, $fx->clock, $fx->ids);

            $fx->tx->run(function () use ($fx, $tenantId, $sourceId, $versionId, $depStore): void {
                $fx->tx->lockTenant($tenantId, LockMode::Update);
                $meta = new NodeMetadata($fx->db, $fx->tx);

                $sourceRef = new ResourceRef($tenantId, 'source', $sourceId);
                $sourceMeta = $meta->resolve($sourceRef);
                $depStore->register(
                    new Node($sourceRef, new Fingerprint(HashDomain::DependencyNodeIdentity->digest($sourceMeta['identity']))),
                    new Parents($tenantId, []),
                );

                $versionRef = new ResourceRef($tenantId, 'source_version', $versionId);
                $versionMeta = $meta->resolve($versionRef);
                $depStore->register(
                    new Node($versionRef, new Fingerprint(HashDomain::DependencyNodeIdentity->digest($versionMeta['identity']))),
                    new Parents($tenantId, [$sourceRef]),
                );
            });

            $service = new DirectivePromotionService($fx->owner, $layerStore, $depStore, $fx->clock, $fx->tx);
            $scope = $fx->scope(1);
            $directiveId = new Ulid(CatalogFixture::id(501));
            $sourceVersionRef = new SourceVersionRef($tenantId, $sourceId, $versionId, $sourceDigest);

            $artifact = $service->promote(
                $scope,
                new PromoteDirective(
                    directiveId: $directiveId,
                    version: 1,
                    title: 'PG Lineage Directive',
                    contentFormat: 'compact_markdown',
                    body: 'Must remain grounded in active source.',
                    lockedRules: new JsonObject(['answer.must_cite' => true]),
                    derivedFrom: [$sourceVersionRef],
                    approvalDigest: new Sha256(str_repeat('5', 64)),
                ),
                $fx->context('promote-pg-1', null, 'current'),
            );

            self::assertNotNull($layerStore->findDirective($tenantId, $directiveId));

            $directiveRef = new ResourceRef($tenantId, 'directive_artifact', $artifact->directiveId);
            $beforeDecision = $fx->tx->run(function () use ($fx, $tenantId, $depStore, $directiveRef): Decision {
                $fx->tx->lockTenant($tenantId, LockMode::Share);
                return $depStore->checkAncestors(new NodeRefs($tenantId, [$directiveRef]))->decision;
            });
            self::assertSame(Decision::Allow, $beforeDecision);

            // Empty derivedFrom skips dependency registration cleanly.
            $standaloneId = new Ulid(CatalogFixture::id(502));
            $service->promote(
                $scope,
                new PromoteDirective(
                    directiveId: $standaloneId,
                    version: 1,
                    title: 'Standalone Directive',
                    contentFormat: 'compact_markdown',
                    body: 'No source lineage.',
                    lockedRules: new JsonObject([]),
                    derivedFrom: [],
                    approvalDigest: new Sha256(str_repeat('6', 64)),
                ),
                $fx->context('promote-pg-2', null, 'current'),
            );
            self::assertNotNull($layerStore->findDirective($tenantId, $standaloneId));

            // Invalidate the derivedFrom source and verify synchronous Deny on checkAncestors().
            $revokeEvent = new LifecycleEvent($fx->events->create(
                $tenantId,
                'source',
                $sourceId,
                new Decimal('1'),
                'source.revoked',
                new JsonObject([
                    'sourceId' => $sourceId->value,
                    'reason' => 'source_revoked',
                    'lifecycleEpoch' => '1',
                    'appliesTo' => 'future_versions',
                ]),
            ));

            $fx->tx->run(function () use ($fx, $tenantId, $sourceId, $depStore, $revokeEvent): void {
                $fx->tx->lockTenant($tenantId, LockMode::Update);
                $fx->db->execute(
                    "UPDATE {$fx->db->table('sources')} SET lifecycle_state='revoked',lifecycle_epoch=1 WHERE tenant_id=? AND id=?",
                    [$tenantId->value, $sourceId->value],
                );
                $depStore->invalidate($revokeEvent);
            });

            $afterDecision = $fx->tx->run(function () use ($fx, $tenantId, $depStore, $directiveRef): Decision {
                $fx->tx->lockTenant($tenantId, LockMode::Share);
                return $depStore->checkAncestors(new NodeRefs($tenantId, [$directiveRef]))->decision;
            });
            self::assertSame(Decision::Deny, $afterDecision);

            // Promoting a new directive against the revoked source fails before saving directive_artifacts.
            $blockedId = new Ulid(CatalogFixture::id(503));
            try {
                $service->promote(
                    $scope,
                    new PromoteDirective(
                        directiveId: $blockedId,
                        version: 1,
                        title: 'Blocked Directive',
                        contentFormat: 'compact_markdown',
                        body: 'Should not persist.',
                        lockedRules: new JsonObject([]),
                        derivedFrom: [$sourceVersionRef],
                        approvalDigest: new Sha256(str_repeat('7', 64)),
                    ),
                    $fx->context('promote-pg-3', null, 'current'),
                );
                self::fail('Expected DomainFailure for revoked derivedFrom source');
            } catch (DomainFailure $e) {
                self::assertSame(Outcome::Denied, $e->outcome);
                self::assertSame('ancestor_unusable', $e->errorCode);
            }
            self::assertNull($layerStore->findDirective($tenantId, $blockedId));
        });
    }
}
