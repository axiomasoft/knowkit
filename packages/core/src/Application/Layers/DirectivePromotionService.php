<?php
declare(strict_types=1);

namespace KnowKit\Application\Layers;

use KnowKit\Domain\{
    Decision,
    DomainFailure,
    Fingerprint,
    LockMode,
    MutationContext,
    Node,
    Operation,
    Outcome,
    Parents,
    ResourceRef,
    Scope,
    Sha256,
    SourceVersionRef,
    Ulid,
    ValueGuard,
};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Domain\Layers\DirectiveArtifact;
use KnowKit\Port\{AccessPolicy, CatalogTransaction, Clock, DependencyStore, LayerStore};

final readonly class PromoteDirective
{
    /**
     * @param list<SourceVersionRef> $derivedFrom
     */
    public function __construct(
        public Ulid $directiveId,
        public int $version,
        public string $title,
        public string $contentFormat,
        public string $body,
        public JsonObject $lockedRules,
        public array $derivedFrom,
        public Sha256 $approvalDigest,
    ) {
        ValueGuard::objects($derivedFrom, SourceVersionRef::class, 64);
    }
}

final readonly class DirectivePromotionService
{
    public function __construct(
        private AccessPolicy $accessPolicy,
        private LayerStore $layerStore,
        private DependencyStore $dependencyStore,
        private Clock $clock,
        private ?CatalogTransaction $tx = null,
    ) {
    }

    public function promote(Scope $scope, PromoteDirective $command, MutationContext $mutation): DirectiveArtifact
    {
        if ($mutation->expectedEpoch() !== null && !$mutation->expectedEpoch()->equals($scope->policyEpoch)) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
        foreach ($command->derivedFrom as $sourceRef) {
            $scope->assertTenant($sourceRef->tenantId);
        }

        $resource = new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        $receipt = $this->accessPolicy->decide($scope, $resource, Operation::DirectivePromote);
        $binding = $receipt->toWire()->get('binding');
        $bindingDigest = new Sha256(HashDomain::PolicyBinding->digest($binding));
        $now = $this->clock->now();
        $receipt->requireAllow($scope, $resource, Operation::DirectivePromote, $now, $bindingDigest);

        $artifact = new DirectiveArtifact(
            tenantId: $scope->tenantId,
            directiveId: $command->directiveId,
            version: $command->version,
            title: $command->title,
            contentFormat: $command->contentFormat,
            body: $command->body,
            bodyDigest: new Sha256(hash('sha256', $command->body)),
            lockedRules: $command->lockedRules,
            promotedBy: $scope->actorId,
            promotedAt: $now,
            derivedFrom: $command->derivedFrom,
            approvalDigest: $command->approvalDigest,
        );

        if ($this->tx !== null) {
            $this->tx->run(function () use ($scope, $artifact, $command): void {
                $this->tx->lockTenant($scope->tenantId, LockMode::Update);
                $this->persist($scope, $artifact, $command);
            });
        } else {
            $this->persist($scope, $artifact, $command);
        }

        return $artifact;
    }

    private function persist(Scope $scope, DirectiveArtifact $artifact, PromoteDirective $command): void
    {
        $parents = null;
        if ($command->derivedFrom !== []) {
            $parentRefs = array_map(
                static fn(SourceVersionRef $ref): ResourceRef => new ResourceRef(
                    $ref->tenantId,
                    'source_version',
                    $ref->sourceVersionId,
                ),
                $command->derivedFrom,
            );
            $parents = new Parents($scope->tenantId, $parentRefs);
            $report = $this->dependencyStore->checkAncestors($parents);
            if ($report->decision !== Decision::Allow) {
                throw new DomainFailure(
                    $report->decision === Decision::Deny ? Outcome::Denied : Outcome::Unknown,
                    $report->decision === Decision::Deny ? 'ancestor_unusable' : 'ancestor_unknown',
                );
            }
        }

        $this->layerStore->saveDirective($artifact);

        if ($parents !== null) {
            $identity = new JsonObject([
                'tenantId' => $scope->tenantId->value,
                'directiveId' => $command->directiveId->value,
                'artifactDigest' => $artifact->artifactDigest()->value,
            ]);
            $directiveNode = new Node(
                new ResourceRef($scope->tenantId, 'directive_artifact', $command->directiveId),
                new Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity)),
            );
            $this->dependencyStore->register($directiveNode, $parents);
        }
    }
}
