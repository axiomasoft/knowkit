<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\AccessPolicy;
use KnowKit\Port\EgressPolicy;
use KnowKitExample\Host;
use KnowKitExample\Runtime;

/** Synthetic current Content/Identity authority, separate from resolver ranking and read grants. */
final class HostAuthority implements HostIdentity, AccessPolicy, EgressPolicy, InvocationAuthorization
{
    public bool $classAllowed = true;
    public bool $consentAllowed = true;
    public bool $known = true;
    public bool $publicationAllowed = true;
    public bool $processingAllowed = true;
    public bool $historyAllowed = true;
    /** @var list<string> */
    public array $deniedEgressCategories = [];
    /** @var list<string> */
    public array $observedEgressCategories = [];
    public ?JsonObject $bindingOverride = null;
    public ?\Closure $onBeforeSend = null;

    public function __construct(
        public readonly Runtime $runtime,
        public HostScope $inputs,
        public KbResolverPort $resolver,
        public KbContext $kbContext,
        public readonly MaterialAdapter $materials,
        public readonly PassagePin $passage,
        private readonly LessonBindings $bindings,
    ) {
    }

    public function current(): PolicyContext
    {
        $s = $this->runtime->host->scope();
        $scope = new D\Scope($s->tenantId, $s->kbId, new D\ActorId(Host::id($this->inputs->actor)),
            $this->inputs->purpose, $s->policyEpoch, $this->inputs->hash());

        return new PolicyContext($scope, 'fixture:content-owner', []);
    }

    public function context(): AccessContext
    {
        return AccessContext::fromHost($this);
    }

    public function requireCurrent(D\Scope $scope): void
    {
        if (!$this->known) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'host_authority_unknown');
        }
        if ($scope != $this->current()->scope || !$this->classAllowed || !$this->consentAllowed) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $binding = $this->bindings->conversationFor($this->inputs->lesson ?? 'workspace:1');
        if ($binding->revision !== $this->inputs->revisions->lesson || $binding->kb != $scope->kbId) {
            throw new D\DomainFailure(D\Outcome::Denied, 'lesson_binding_changed');
        }
    }

    /** Trusted eligibility is passed separately to core and intersected BEFORE top-k. @return list<D\SourceVersionId> */
    public function eligible(): array
    {
        return $this->resolver->resolve($this->inputs->topic, $this->kbContext)->sourceVersions($this->inputs->audienceKind);
    }

    public function authorizeQuery(D\Scope $scope): D\QueryGrant
    {
        $this->requireCurrent($scope);
        // Deliberately broader read grant: core must still intersect with resolver eligibility.
        // A read grant is not permission to add a KB removed by override/age/scope/deny.
        return $this->runtime->host->authorizeQuery($scope);
    }

    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->requireCurrent($scope);
        $scope->assertTenant($resource->tenantId);
        if ($operation === D\Operation::Publish && !$this->publicationAllowed) {
            throw new D\DomainFailure(D\Outcome::Denied, 'publication_denied');
        }
        if (in_array($resource->kind, ['source', 'source_version'], true)) {
            $this->requirePassage();
            $eligible = array_map(static fn (D\SourceVersionId $id): string => $id->value, $this->eligible());
            if (!in_array(Host::id('demo-version'), $eligible, true)
                || ($resource->kind === 'source' && $resource->id->value !== Host::id('demo-source'))
                || ($resource->kind === 'source_version' && !in_array($resource->id->value, $eligible, true))) {
                throw new D\DomainFailure(D\Outcome::Denied, 'host_source_excluded');
            }
        }

        return $this->receipt($scope, [$resource], $operation);
    }

    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        $this->requireCurrent($scope);
        $this->requirePassage();
        $wire = $request->toWire();
        $destination = $wire->get('destination');
        if (!$this->processingAllowed || $destination->get('providerId') !== 'fixture'
            || $destination->get('accountId') !== $this->inputs->project
            || $destination->get('region') !== 'local'
            || !in_array($destination->get('modelId'), ['fixture-model:1', 'fixture-model:2'], true)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'destination_denied');
        }
        $resources = [];
        foreach ($wire->get('items') as $item) {
            $category = $item->get('category');
            $this->observedEgressCategories[] = $category;
            if (in_array($category, $this->deniedEgressCategories, true)) {
                throw new D\DomainFailure(D\Outcome::Denied, 'content_egress_denied');
            }
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $scope->assertTenant(new D\TenantId($ref->get('tenantId')));
                $resource = new D\ResourceRef($scope->tenantId, 'source_version', new D\SourceVersionId($ref->get('sourceVersionId')));
                $this->decide($scope, $resource, D\Operation::ModelEgressContext);
                $resources[$resource->id->value] = $resource;
            }
        }
        if ($resources === []) {
            $resources[] = new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        }

        return $this->receipt($scope, array_values($resources), D\Operation::ModelEgressContext);
    }

    public function beforeSend(D\Scope $scope, A\ModelCallIdentity $identity): ?A\AnswerOutcome
    {
        if ($this->onBeforeSend !== null) {
            ($this->onBeforeSend)();
        }

        return $this->authorizeDependencies($scope, $identity->invocationRef, $identity->dependencies);
    }

    public function beforeReplay(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->authorizeDependencies($scope, $record->invocationRef, $record->dependencies);
    }

    public function beforeResume(D\Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->beforeReplay($scope, $record);
    }

    public function requirePassage(): void
    {
        $result = $this->materials->current($this->inputs, $this->passage);
        if ($result->status === PassageStatus::Unavailable) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'passage_unavailable');
        }
        if ($result->status !== PassageStatus::Available) {
            throw new D\DomainFailure(D\Outcome::Denied, 'passage_not_current');
        }
    }

    private function authorizeDependencies(D\Scope $scope, A\InvocationRef $ref, ?A\DependencyManifest $dependencies): ?A\AnswerOutcome
    {
        try {
            $this->requireCurrent($scope);
            $this->requirePassage();
            if (!$this->publicationAllowed || !$this->processingAllowed || $dependencies === null
                || ($dependencies->history !== [] && !$this->historyAllowed)) {
                throw new D\DomainFailure(D\Outcome::Denied, 'host_rights_denied');
            }
            foreach ($dependencies->evidence as $citation) {
                $version = $citation->toWire()->get('sourceVersionId');
                $this->decide($scope, new D\ResourceRef($scope->tenantId, 'source_version', new D\SourceVersionId($version)), D\Operation::Disclose);
                $this->requireLiveVersion($scope, $version);
            }

            return null;
        } catch (D\DomainFailure $failure) {
            $code = $failure->outcome === D\Outcome::Denied ? A\OutcomeCode::Denied : A\OutcomeCode::PolicyUnknown;

            return new A\AnswerOutcome($code, $ref, A\DispatchCertainty::KnownNotSent, fixture: true,
                reason: $code === A\OutcomeCode::Denied ? A\DenialReason::Revoked : null);
        }
    }

    private function requireLiveVersion(D\Scope $scope, string $version): void
    {
        $sql = <<<'SQL'
            SELECT s.lifecycle_state,v.usability,
              EXISTS (SELECT 1 FROM tombstones t WHERE t.tenant_id=s.tenant_id AND t.source_id=s.id) AS tombstoned
            FROM source_versions r JOIN sources s ON s.tenant_id=r.tenant_id AND s.id=r.source_id
            JOIN source_version_state v ON v.tenant_id=r.tenant_id AND v.id=r.id
            WHERE r.tenant_id=? AND r.id=?
            SQL;
        $row = $this->runtime->host->db->row($sql, [$scope->tenantId->value, $version]);
        if ($row === null || $row['lifecycle_state'] !== 'active' || $row['usability'] !== 'usable' || $row['tombstoned']) {
            throw new D\DomainFailure(D\Outcome::Denied, 'source_revoked');
        }
    }

    /** @param non-empty-list<D\ResourceRef> $resources */
    private function receipt(D\Scope $scope, array $resources, D\Operation $operation): D\DecisionReceipt
    {
        $host = $this->runtime->host;

        return (new ReceiptFactory($host->validator, $host))->issue(D\Decision::Allow, $scope,
            $resources, $operation, $this->bindingOverride ?? $host->binding(), 'fixture:current:1');
    }
}
