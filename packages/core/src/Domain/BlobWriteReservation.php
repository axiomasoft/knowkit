<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Created durably by the ledger BEFORE writing any bytes. Null digest uses exact stagingId. */
final readonly class BlobWriteReservation
{
    public function __construct(public ResourceRef $resource, public SourceId $sourceId, public WriterId $writerId,
        public Decimal $fence, public Decimal $sourceEpoch, public ?Sha256 $digest, public ?Uint64 $byteLength,
        public ?Ulid $stagingId)
    {
        if ($resource->kind !== 'resource' || (($digest === null) !== ($byteLength === null))
            || ($digest === null && $stagingId === null)) { throw new \InvalidArgumentException('Exact pre-PUT reservation required'); }
    }
}
