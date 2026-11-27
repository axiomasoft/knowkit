<?php

declare(strict_types=1);

namespace KnowKit\Domain\Pruning;

use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ValueGuard;

final readonly class PrunableUnit
{
    /**
     * @param list<AtomicByteRange> $atomicRanges
     */
    public function __construct(
        public Chunk $chunk,
        public array $atomicRanges = [],
        public ?int $contextEndByte = null,
    ) {
        ValueGuard::objects($atomicRanges, AtomicByteRange::class, 1024);
        $len = strlen($chunk->text());
        foreach ($atomicRanges as $range) {
            if ($range->endByte > $len) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
            }
        }
        if ($contextEndByte !== null && ($contextEndByte < 0 || $contextEndByte > $len)) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
    }

    public function chunkRef(): ChunkRef
    {
        return $this->chunk->ref;
    }

    public function text(): string
    {
        return $this->chunk->text();
    }
}
