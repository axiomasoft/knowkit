<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{Decision, DecisionReceipt, DomainFailure, InstanceId, Operation, Outcome, QueryGrant, ResourceRef, Scope, Sha256, SourceVersionId, Ulid};
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, JsonObject};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port\{AccessPolicy, Clock};

/**
 * Instance-scoped allow policy for single-local hosts.
 * Never registered as a default in AdapterRegistry; grants Allow strictly for its bound (tenantId, kbId, hostScopeHash).
 */
final readonly class ScopedAllowPolicy implements AccessPolicy
{
    /**
     * @param ?\Closure(Scope): list<SourceVersionId> $eligibleSourceVersions
     * @param ?\Closure(Scope, ?ResourceRef, ?Operation): JsonObject $bindingResolver
     */
    public function __construct(
        private InstanceId $instanceId,
        private Ulid $tenantId,
        private Ulid $kbId,
        private Sha256 $expectedHostScopeHash,
        private Clock $clock,
        private ContractValidator $validator,
        private DisclosureMode $defaultDisclosureMode = DisclosureMode::VerbatimAllowed,
        private ?\Closure $eligibleSourceVersions = null,
        private ?\Closure $bindingResolver = null,
    ) {
    }

    public function authorizeQuery(Scope $scope): QueryGrant
    {
        if (!$this->matchesScope($scope)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $eligible = $this->eligibleSourceVersions !== null ? ($this->eligibleSourceVersions)($scope) : [];
        $binding = $this->binding($scope);

        return new QueryGrant(new JsonObject([
            'allowedCollectionIds' => [],
            'allowedSourceVersionIds' => array_map(static fn(SourceVersionId $id): string => $id->value, $eligible),
            'grantEpoch' => $scope->policyEpoch->value,
            'policyBindingDigest' => HashDomain::PolicyBinding->digest($binding),
            'expiresAt' => $this->clock->now()->plusSeconds(5)->value,
        ]), $this->validator);
    }

    public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
    {
        $scope->assertTenant($resource->tenantId);
        $allowed = $this->matchesScope($scope)
            && $resource->tenantId->value === $this->tenantId->value
            && ($resource->kind !== 'knowledge_base' || $resource->id->value === $this->kbId->value);

        $now = $this->clock->now();
        $wire = new JsonObject([
            'decision' => $allowed ? Decision::Allow->value : Decision::Deny->value,
            'operation' => $operation->value,
            'resourceRefs' => [$resource->toWire()],
            'binding' => $this->binding($scope, $resource, $operation),
            'ownerRevision' => 'builtin:scoped-allow:' . $this->instanceId->value . ':v1',
            'checkedAt' => $now->value,
            'expiresAt' => $now->plusSeconds(5)->value,
            'hostScopeHash' => $scope->hostScopeHash->value,
            'maxDisclosureMode' => $this->defaultDisclosureMode->value,
        ]);

        return new DecisionReceipt(
            $wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)),
            $this->validator,
        );
    }

    private function matchesScope(Scope $scope): bool
    {
        return $scope->tenantId->value === $this->tenantId->value
            && $scope->kbId->value === $this->kbId->value
            && $scope->hostScopeHash->equals($this->expectedHostScopeHash);
    }

    private function binding(Scope $scope, ?ResourceRef $resource = null, ?Operation $operation = null): JsonObject
    {
        if ($this->bindingResolver !== null) {
            return ($this->bindingResolver)($scope, $resource, $operation);
        }

        return new JsonObject([
            'tenantPolicyEpoch' => '0',
            'kbPolicyEpoch' => $scope->policyEpoch->value,
            'sources' => [],
        ]);
    }
}
