<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class JobResult
{
    public function __construct(public Outcome $outcome, public Sha256 $resultDigest, public ?Coverage $coverage = null) {}
}
