<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SourceVersion
{
    /** blob is the originalBlobRef; ref carries the immutable sourceDigest and IDs. */
    public function __construct(public SourceVersionRef $ref, public BlobRef $blob,
        public string $mime, public string $rightsRef, public UtcInstant $createdAt)
    {
        ValueGuard::text($mime, 128); ValueGuard::text($rightsRef);
        if (!$ref->tenantId->equals($blob->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        if (!$ref->sourceId->equals($blob->sourceId) || $ref->sourceDigest->value !== $blob->digest->value) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
