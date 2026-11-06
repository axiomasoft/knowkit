<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SourceVersion
{
    public function __construct(public SourceVersionRef $ref, public BlobRef $blob)
    {
        if (!$ref->tenantId->equals($blob->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        if (!$ref->sourceId->equals($blob->sourceId) || $ref->sourceDigest->value !== $blob->digest->value) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
