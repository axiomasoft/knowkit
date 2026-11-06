<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class ResourcePage
{
    /** @param list<ResourceRef> $resources */
    public function __construct(public TenantId $tenantId, public array $resources, public ?Cursor $next)
    {
        ValueGuard::objects($resources, ResourceRef::class);
        foreach ($resources as $ref) {
            if (!$tenantId->equals($ref->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        }
        if ($next !== null && !$tenantId->equals($next->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
    }
}
