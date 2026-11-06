<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Backend scores/ranks are reference metadata, never canonical excerpt text. */
final readonly class Candidate
{
    public function __construct(public ChunkRef $ref, public int $rank, public ?float $score = null)
    {
        if ($rank < 1 || ($score !== null && !is_finite($score))) { throw new \InvalidArgumentException('Finite ranked candidate required'); }
    }
}
