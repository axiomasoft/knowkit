<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Trusted host pagination binding. Adapter authenticates the opaque continuation against this binding. */
final readonly class PageCursor
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public CollectionFilter $filter,
        public Sha256 $securityScopeDigest, public ?string $token = null, public int $limit = 100)
    {
        if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Page limit must be 1..100'); }
        if ($token !== null) { ValueGuard::text($token, 1024); }
    }

    public function assertBinding(TenantId $tenantId, KbId $kbId, CollectionFilter $filter, Sha256 $securityScopeDigest): void
    {
        if (!$this->tenantId->equals($tenantId) || !$this->kbId->equals($kbId)
            || $this->filter->collectionId?->value !== $filter->collectionId?->value
            || !hash_equals($this->securityScopeDigest->value, $securityScopeDigest->value)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function __debugInfo(): array { return ['tenantId'=>$this->tenantId, 'kbId'=>$this->kbId, 'limit'=>$this->limit]; }
}
