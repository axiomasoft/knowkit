<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain\CandidateList;

/** Contains references and operational metrics only, never document/query text or vectors. */
final readonly class SearchResult
{
    public function __construct(
        public CandidateList $candidates,
        public string $requestedMode,
        public string $effectiveMode,
        public string $fallbackPolicy,
        public bool $fallbackUsed,
        public ?float $annRecall,
        public int $permittedCount,
    ) {}
}
