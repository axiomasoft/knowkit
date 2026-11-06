<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** In-memory original bound to a prior durable reservation; no unreserved spool path. */
final readonly class SourceUpload
{
    public function __construct(public SourceBytes $source, public BlobWriteReservation $reservation)
    {
        if (!$source->ref->tenantId->equals($reservation->resource->tenantId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        if (!$source->ref->sourceId->equals($reservation->sourceId)
            || ($reservation->digest !== null && $source->ref->sourceDigest->value !== $reservation->digest->value)
            || ($reservation->byteLength !== null && $reservation->byteLength->value !== (string) $source->byteLength())) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
