<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Node
{
    public function __construct(public ResourceRef $ref, public Fingerprint $identityDigest) {}
    public function assertParents(Parents $parents): void
    {
        if (!$this->ref->tenantId->equals($parents->tenantId)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        foreach ($parents->refs as $parent) {
            if ($this->ref->equals($parent)) {
                throw new DomainFailure(Outcome::Incompatible, 'self_reference');
            }
        }
    }
}
