<?php

declare(strict_types=1);

namespace KnowKit\Domain\Pruning;

use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ValueGuard;

final readonly class PrunedSelection
{
    /**
     * @param list<PrunedSubspan> $subspans
     */
    public function __construct(
        public ChunkRef $chunkRef,
        public array $subspans,
    ) {
        ValueGuard::objects($subspans, PrunedSubspan::class, 1024);
        if ($subspans === []) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
    }
}
