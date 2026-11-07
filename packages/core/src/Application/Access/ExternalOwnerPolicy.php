<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{DecisionReceipt, DomainFailure, EgressRequest, Operation, Outcome, QueryGrant, ResourceRef, Scope, Sha256};
use KnowKit\Domain\Access\OwnerFreshness;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain};
use KnowKit\Port\{AccessPolicy, Clock, EgressPolicy};

/** Always consult the current host owner. A local projection never supplies an Allow. */
final class ExternalOwnerPolicy implements AccessPolicy, EgressPolicy
{
    private ?DecisionReceipt $projection = null;

    public function __construct(private readonly AccessPolicy $access, private readonly EgressPolicy $egress,
        private readonly Clock $clock, private readonly ?OwnerFreshness $freshness,
        private readonly ContractValidator $validator) {}

    public function freshness(): ?OwnerFreshness { return $this->freshness; }

    public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt
    {
        return $this->observe(fn(): DecisionReceipt => $this->access->decide($scope, $resource, $operation));
    }

    public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt
    {
        return $this->observe(fn(): DecisionReceipt => $this->egress->mayEgress($scope, $request));
    }

    private function observe(\Closure $call): DecisionReceipt
    {
        $this->projection = null; // Invalidate even if the owner throws; never fall back.
        try { $receipt = $call(); }
        catch (DomainFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new DomainFailure(Outcome::Unavailable, 'policy_unavailable'); }
        if ($this->freshness === null) { throw new DomainFailure(Outcome::Unknown, 'owner_freshness_unknown'); }
        $this->freshness->requireFresh($receipt, $this->clock->now());
        return $this->projection = $receipt;
    }

    /** Reference-only observation for diagnostics; not accepted by an authorization method. */
    public function projection(): ?DecisionReceipt { return $this->projection; }

    public function authorizeQuery(Scope $scope): QueryGrant
    {
        $freshness = $this->freshness ?? throw new DomainFailure(Outcome::Unknown, 'owner_freshness_unknown');
        $resource = new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        $receipt = $this->decide($scope, $resource, Operation::Retrieve);
        $binding = new Sha256(HashDomain::PolicyBinding->digest($receipt->toWire()->get('binding')));
        $receipt->requireAllow($scope, $resource, Operation::Retrieve, $this->clock->now(), $binding);
        try { $grant = $this->access->authorizeQuery($scope); }
        catch (DomainFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new DomainFailure(Outcome::Unavailable, 'policy_unavailable'); }
        $freshness->requireFresh($receipt, $this->clock->now());
        $wire = $grant->toWire();
        if ($wire->get('policyBindingDigest') !== $binding->value || $wire->get('grantEpoch') !== $scope->policyEpoch->value) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
        $expires = new \KnowKit\Domain\UtcInstant($wire->get('expiresAt'));
        $receiptExpires = new \KnowKit\Domain\UtcInstant($receipt->toWire()->get('expiresAt'));
        $windowExpires = (new \KnowKit\Domain\UtcInstant($receipt->toWire()->get('checkedAt')))->plusSeconds($freshness->maxWindowSeconds);
        if ($receiptExpires->compare($expires) < 0) { $expires = $receiptExpires; }
        if ($windowExpires->compare($expires) < 0) { $expires = $windowExpires; }
        if ($expires->compare($this->clock->now()) <= 0) { throw new DomainFailure(Outcome::Unknown, 'owner_freshness_unknown'); }
        // Narrow, never extend, the host grant's validity.
        return new QueryGrant($wire->with('expiresAt', $expires->value), $this->validator);
    }
}
