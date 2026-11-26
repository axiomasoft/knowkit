<?php
declare(strict_types=1);

namespace KnowKit\Domain;

readonly class NodeRefs
{
    /** @param list<ResourceRef> $refs */
    public function __construct(public TenantId $tenantId, public array $refs)
    {
        ValueGuard::objects($refs, ResourceRef::class);
        $seen = [];
        foreach ($refs as $ref) {
            if (!$tenantId->equals($ref->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            // Tenant is already equal; kind + id are the remaining wire identity. NUL blocks concatenation collisions.
            $key = $ref->kind . "\0" . $ref->id->value;
            if (isset($seen[$key])) { throw new DomainFailure(Outcome::Incompatible, 'duplicate_identifier'); }
            $seen[$key] = true;
        }
    }
}
