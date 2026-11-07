<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class QueryGrant extends SchemaValue
{
    protected const SCHEMA = 'query-grant';

    /** Eligibility can cover a wider universe; exact resource epochs are checked by decide(Navigate/Disclose). */
    public function requireNavigation(Scope $scope, DocumentRevisionRef $revision, UtcInstant $now): void
    {
        $wire = $this->toWire();
        $scope->assertTenant($revision->sourceRef->tenantId);
        if (!in_array($revision->sourceRef->sourceVersionId->value, $wire->get('allowedSourceVersionIds'), true)) {
            throw new DomainFailure(Outcome::Denied, 'source_not_in_scope');
        }
        if ($wire->get('grantEpoch') !== $scope->policyEpoch->value
            || $this->expiresAt()->compare($now) <= 0) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }
    }

    public function expiresAt(): UtcInstant
    {
        return new UtcInstant($this->toWire()->get('expiresAt'));
    }
}
