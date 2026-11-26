<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class ChunkRef
{
    public function __construct(public DocumentRevisionRef $revisionRef, public ChunkSetId $chunkSetId,
        public ChunkId $chunkId, public Sha256 $chunkDigest) {}
    public function toWire(): JsonObject
    {
        return $this->revisionRef->toWire()->with('chunkSetId', $this->chunkSetId->value)
            ->with('chunkId', $this->chunkId->value)->with('chunkDigest', $this->chunkDigest->value);
    }

    public function equals(self $other): bool
    {
        return $this->revisionRef->equals($other->revisionRef)
            && $this->chunkSetId->equals($other->chunkSetId)
            && $this->chunkId->equals($other->chunkId)
            && $this->chunkDigest->equals($other->chunkDigest);
    }
}
