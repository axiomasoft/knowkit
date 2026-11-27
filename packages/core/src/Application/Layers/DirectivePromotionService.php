<?php
declare(strict_types=1);

namespace KnowKit\Application\Layers;

use KnowKit\Domain\{
    DomainFailure,
    MutationContext,
    Node,
    Operation,
    Outcome,
    Parents,
    ResourceId,
    ResourceRef,
    Scope,
    Sha256,
    SourceVersionRef,
    Ulid,
    ValueGuard,
};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Domain\Layers\DirectiveArtifact;
use KnowKit\Port\{AccessPolicy, Clock, DependencyStore, LayerStore};

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

        $this->layerStore->saveDirective($artifact);

        $directiveNode = new Node(
            new ResourceRef($scope->tenantId, 'resource', new ResourceId($command->directiveId->value)),
            $artifact->artifactDigest(),
        );
        $parentRefs = array_map(
            static fn(SourceVersionRef $ref): ResourceRef => new ResourceRef(
                $ref->tenantId,
                'source_version',
                $ref->sourceVersionId,
            ),
            $command->derivedFrom,
        );
        $this->dependencyStore->register($directiveNode, new Parents($scope->tenantId, $parentRefs));

        return $artifact;
    }
}
