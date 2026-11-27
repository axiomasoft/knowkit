<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Weighted ranking channel for Weighted Reciprocal Rank Fusion (integer-only weightBps). */
final readonly class WeightedRanking
{
    /** @param list<Candidate> $candidates */
    public function __construct(
        public array $candidates,
        public int $weightBps,
    ) {
        ValueGuard::objects($candidates, Candidate::class, 512);
        if ($weightBps < 1 || $weightBps > 10000) {
            throw new \InvalidArgumentException('weightBps must be integer 1..10000');
        }
    }
}
