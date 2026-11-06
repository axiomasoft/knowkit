<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

final readonly class Scope
{
    public function __construct(
        public TenantId $tenantId, public KbId $kbId, public ActorId $actorId,
        public string $purpose, public Decimal $policyEpoch, public Sha256 $hostScopeHash,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){1,3}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Namespaced business purpose required');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId' => $this->tenantId->value, 'kbId' => $this->kbId->value,
            'actorId' => $this->actorId->value, 'purpose' => $this->purpose,
            'policyEpoch' => $this->policyEpoch->value, 'hostScopeHash' => $this->hostScopeHash->value]);
    }

    public function bindingDigest(Sha256 $policyBindingDigest): Fingerprint
    {
        return new Fingerprint(HashDomain::ScopeBinding->digest($this->toWire()->without('policyEpoch')
            ->with('policyBindingDigest', $policyBindingDigest->value)));
    }

    public function assertTenant(TenantId $tenant): void
    {
        if (!$this->tenantId->equals($tenant)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
    }
}
