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
            || ($digest === null && $stagingId === null)) {
            throw new \InvalidArgumentException('Exact pre-PUT reservation required');
        }
    }

    public function equals(self $other): bool
    {
        return $this->resource->equals($other->resource)
            && $this->sourceId->equals($other->sourceId)
            && $this->writerId->equals($other->writerId)
            && $this->fence->equals($other->fence)
            && $this->sourceEpoch->equals($other->sourceEpoch)
            && ($this->digest === null ? $other->digest === null : ($other->digest !== null && $this->digest->equals($other->digest)))
            && ($this->byteLength === null ? $other->byteLength === null : ($other->byteLength !== null && $this->byteLength->equals($other->byteLength)))
            && ($this->stagingId === null ? $other->stagingId === null : ($other->stagingId !== null && $this->stagingId->equals($other->stagingId)));
    }
}
