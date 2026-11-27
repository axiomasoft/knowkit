<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Layers\{DisclosureMode, ResolutionTier};

final readonly class DecisionReceipt extends SchemaValue
{
    protected const SCHEMA = 'decision-receipt';

    public function maxDisclosureMode(): DisclosureMode
    {
        return DisclosureMode::from($this->toWire()->get('maxDisclosureMode') ?? DisclosureMode::VerbatimAllowed->value);
    }

    public function requireTier(ResolutionTier $tier): void
    {
        $this->assertAllowedDecision();
        if (!$this->maxDisclosureMode()->allowsTier($tier)) {
            throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
        }
    }

    public function requireDirectRead(): void
    {
        $this->assertAllowedDecision();
        if (!$this->maxDisclosureMode()->allowsDirectRead()) {
            throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
        }
    }

    public function requireAuthorized(Scope $scope, Operation $operation, ?ResourceRef $resource = null): void
    {
        if ($resource !== null) {
            $scope->assertTenant($resource->tenantId);
        }
        $value = $this->toWire();
        if ($value->get('hostScopeHash') !== $scope->hostScopeHash->value
            || $value->get('operation') !== $operation->value
            || ($resource !== null && !in_array($resource->toWire(), $value->get('resourceRefs'), false))) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $this->assertAllowedDecision();
    }

    /** Freshness is local evidence only; the host still owns remote-owner freshness. */
    public function requireAllow(Scope $scope, ResourceRef $resource, Operation $operation,
        UtcInstant $now, Sha256 $currentPolicyBindingDigest): void
    {
        $scope->assertTenant($resource->tenantId);
        $value = $this->toWire();
        if ($value->get('hostScopeHash') !== $scope->hostScopeHash->value
            || $value->get('operation') !== $operation->value
            || !in_array($resource->toWire(), $value->get('resourceRefs'), false)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $checkedAt = new UtcInstant($value->get('checkedAt'));
        if ($value->get('binding')->get('kbPolicyEpoch') !== $scope->policyEpoch->value
            || HashDomain::PolicyBinding->digest($value->get('binding')) !== $currentPolicyBindingDigest->value
            || $checkedAt->compare($now) > 0 || $checkedAt->plusSeconds(5)->compare($now) <= 0
            || (new UtcInstant($value->get('expiresAt')))->compare($now) <= 0) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
        $this->assertAllowedDecision();
    }

    private function assertAllowedDecision(): void
    {
        $decision = $this->toWire()->get('decision');
        if ($decision === 'Unknown') {
            throw new DomainFailure(Outcome::Unknown, 'policy_unknown');
        }
        if ($decision !== 'Allow') {
            throw new DomainFailure(Outcome::Denied, 'policy_denied');
        }
    }
}
