<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class ChunkSet
{
    /** @param list<Chunk> $chunks */
    public function __construct(public DocumentRevisionRef $revisionRef, public ChunkSetId $chunkSetId,
        public ParserSignature $chunkerSignature, public array $chunks)
    {
        ValueGuard::objects($chunks, Chunk::class, 1000000);
        $seen = [];
        foreach ($chunks as $chunk) {
            if ($chunk->ref->revisionRef != $revisionRef || !$chunk->ref->chunkSetId->equals($chunkSetId)
                || isset($seen[$chunk->ref->chunkId->value])) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $seen[$chunk->ref->chunkId->value] = true;
        }
    }
}
