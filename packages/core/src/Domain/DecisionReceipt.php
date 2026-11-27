<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Layers\DisclosureMode;

final readonly class DecisionReceipt extends SchemaValue
{
    protected const SCHEMA = 'decision-receipt';

    public function maxDisclosureMode(): DisclosureMode
    {
        return DisclosureMode::from($this->toWire()->get('maxDisclosureMode') ?? DisclosureMode::VerbatimAllowed->value);
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
        if ($value->get('decision') === 'Unknown') { throw new DomainFailure(Outcome::Unknown, 'policy_unknown'); }
        if ($value->get('decision') !== 'Allow') { throw new DomainFailure(Outcome::Denied, 'policy_denied'); }
    }
}
