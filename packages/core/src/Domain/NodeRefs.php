<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\Jcs;

readonly class NodeRefs
{
    /** @param list<ResourceRef> $refs */
    public function __construct(public TenantId $tenantId, public array $refs)
    {
        ValueGuard::objects($refs, ResourceRef::class);
        $seen = [];
        foreach ($refs as $ref) {
            if (!$tenantId->equals($ref->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            $key = Jcs::canonicalize($ref->toWire());
            if (isset($seen[$key])) { throw new DomainFailure(Outcome::Incompatible, 'duplicate_identifier'); }
            $seen[$key] = true;
        }
    }
}
