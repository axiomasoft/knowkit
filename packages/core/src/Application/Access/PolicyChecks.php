<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{AuthorizedSearchScope, CurrentMemberships, DecisionReceipt, DomainFailure, EgressRequest, GenerationId, Operation, Outcome, Query, ResourceRef, Sha256, SnapshotRef, SourceId, SourceVersionId, TenantId};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Port\{AccessPolicy, Clock, EgressPolicy};

/** Admission, processing/egress and disclosure/publication are separate current decisions. */
final readonly class PolicyChecks
{
    public function __construct(
        private AccessPolicy $access,
        private EgressPolicy $egress,
        private Clock $clock,
    ) {
    }

    /** @param list<SourceVersionId> $hostEligible */
    public function authorizeQuery(
        AccessContext $context,
        SnapshotRef $snapshot,
        GenerationId $generation,
        array $hostEligible,
        JsonObject $binding,
        ?Query $query = null,
        ?CurrentMemberships $memberships = null,
    ): AuthorizedSearchScope {
        $context->assertCurrent();
        try {
            $grant = $this->access->authorizeQuery($context->scope());
        } catch (DomainFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new DomainFailure(Outcome::Unavailable, 'policy_unavailable');
        }
        if ($grant->toWire()->get('policyBindingDigest') !== HashDomain::PolicyBinding->digest($binding)) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
        $context->assertCurrent();
        return AuthorizedSearchScope::materialize(
            $context->scope(),
            $grant,
            $snapshot,
            $generation,
            $hostEligible,
            $this->clock->now(),
            null,
            $query,
            $memberships,
        );
    }

    /**
     * Host supplies the exact resource intersection: KB, applicable collections, sources/versions.
     * It must derive collection/source eligibility from canonical membership, never request claims.
     *
     * @param list<ResourceRef> $resources Nonempty, checked before any owner callback.
     */
    public function prepare(AccessContext $context, array $resources, Operation $operation, JsonObject $binding): PreparedDecision
    {
        if ($resources === []) {
            throw new \InvalidArgumentException('Exact authorization resources required');
        }
        $context->assertCurrent();
        $scope = $context->scope();
        // KB is always an intersection operand. The host policy owns tenant/purpose/operation rights.
        $all = [new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId), ...$resources];
        $receipts = [];
        $seen = [];
        foreach ($all as $resource) {
            $scope->assertTenant($resource->tenantId);
            if ($resource->kind === 'knowledge_base' && !$resource->id->equals($scope->kbId)) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            $key = $resource->kind . ':' . $resource->id->value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $receipt = $this->call(fn(): DecisionReceipt => $this->access->decide($scope, $resource, $operation));
            $receipt->requireAllow(
                $scope,
                $resource,
                $operation,
                $this->clock->now(),
                new Sha256(HashDomain::PolicyBinding->digest($binding)),
            );
            $receipts[] = [$resource, $receipt];
        }
        $context->assertCurrent();
        return new PreparedDecision(
            $context,
            $operation,
            $receipts,
            $this->access instanceof ExternalOwnerPolicy ? $this->access->freshness() : null,
        );
    }

    /**
     * Admission authorizes this action only; it grants neither later publication nor egress.
     *
     * @param list<ResourceRef> $resources
     */
    public function admission(AccessContext $context, array $resources, Operation $operation, JsonObject $binding): void
    {
        if (!in_array($operation, [Operation::Ingest, Operation::Reprocess, Operation::SourceRegister, Operation::SourceVersionAdd], true)) {
            throw new \InvalidArgumentException('Admission operation required');
        }
        $this->prepare($context, $resources, $operation, $binding)->requireAllow($context, $binding, $this->clock->now());
    }

    /**
     * Final gate immediately before a synchronous provider call. Full manifest covers actual bytes.
     *
     * @param array<array-key, mixed> $contents Validated as an ordered list of payload strings.
     * @param list<ResourceRef> $collections Trusted applicable collection refs.
     */
    public function transmit(
        AccessContext $context,
        EgressRequest $request,
        array $contents,
        \Closure $currentBinding,
        \Closure $provider,
        array $collections = [],
    ): mixed {
        $wire = $request->toWire();
        $items = $wire->get('items');
        if (count($contents) !== count($items) || !array_is_list($contents)) {
            throw new DomainFailure(Outcome::Incompatible, 'egress_manifest_mismatch');
        }
        $resources = $collections;
        foreach ($items as $i => $item) {
            if (!is_string($contents[$i]) || hash('sha256', $contents[$i]) !== $item->get('contentDigest')) {
                throw new DomainFailure(Outcome::Incompatible, 'egress_manifest_mismatch');
            }
            foreach ($item->get('sourceVersionRefs') as $ref) {
                $tenant = new TenantId($ref->get('tenantId'));
                $context->scope()->assertTenant($tenant);
                $resources[] = new ResourceRef($tenant, 'source', new SourceId($ref->get('sourceId')));
                $resources[] = new ResourceRef($tenant, 'source_version', new SourceVersionId($ref->get('sourceVersionId')));
            }
        }
        if ($resources === []) {
            $resources[] = new ResourceRef($context->scope()->tenantId, 'knowledge_base', $context->scope()->kbId);
        }
        $operation = Operation::from($wire->get('operation'));
        $binding = $currentBinding($context->scope(), $request);
        $prepared = $this->prepare($context, $resources, $operation, $binding);
        $receipt = $this->call(fn(): DecisionReceipt => $this->egress->mayEgress($context->scope(), $request));
        $context->assertCurrent();
        $current = $currentBinding($context->scope(), $request);
        $prepared->requireAllow($context, $current, $this->clock->now());
        $digest = new Sha256(HashDomain::PolicyBinding->digest($current));
        // Egress owner receipts need source versions (or KB for query-only payloads), not collection ACL refs.
        $targets = [];
        foreach ($resources as $resource) {
            if ($resource->kind === 'source_version') {
                $targets[$resource->kind . ':' . $resource->id->value] = $resource;
            }
        }
        $targets = array_values($targets);
        if ($targets === []) {
            $targets = [new ResourceRef($context->scope()->tenantId, 'knowledge_base', $context->scope()->kbId)];
        }
        foreach ($targets as $resource) {
            $receipt->requireAllow($context->scope(), $resource, $operation, $this->clock->now(), $digest);
        }
        if ($this->egress instanceof ExternalOwnerPolicy) {
            $this->egress->freshness()?->requireFresh($receipt, $this->clock->now());
        }
        return $provider($contents);
    }

    private function call(\Closure $call): DecisionReceipt
    {
        try {
            return $call();
        } catch (DomainFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new DomainFailure(Outcome::Unavailable, 'policy_unavailable');
        }
    }
}
