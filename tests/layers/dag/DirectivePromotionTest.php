<?php
declare(strict_types=1);

namespace KnowKit\Tests\Layers\Dag;

use KnowKit\Application\Layers\{DirectivePromotionService, PromoteDirective};
use KnowKit\Domain\{
    ActorId,
    Decision,
    DecisionReceipt,
    Decimal,
    DomainFailure,
    KbId,
    LifecycleEvent,
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
use PHPUnit\Framework\TestCase;

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
}
