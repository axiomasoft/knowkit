<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain as D;

/** Exact SQL prefilter is the default; ANN runs only on an already authorized temporary corpus. */
final readonly class SearchPolicy
{
    public function __construct(
        public string $mode = 'exact',
        public int $efSearch = 40,
        public string $fallback = 'exact',
        string $filterCapability = 'sql-prefilter',
        public string $iterativeScan = 'off',
        public int $maxScanTuples = 20000,
        public int $oversampleFactor = 10,
    ) {
        if ($filterCapability !== 'sql-prefilter') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'prefilter_unsupported');
        }
        if (!in_array($mode, ['exact', 'authorized-hnsw'], true)
            || !in_array($fallback, ['exact', 'reject'], true)
            || !in_array($iterativeScan, ['off', 'relaxed_order', 'strict_order'], true)
            || $efSearch < 1 || $efSearch > 1000
            || $maxScanTuples < 1 || $maxScanTuples > 1_000_000
            || $oversampleFactor < 1 || $oversampleFactor > 64) {
            throw new \InvalidArgumentException('Explicit vector search policy required');
        }
    }

    /** @param list<string> $exact @param list<string> $ann */
    public function recall(array $exact, array $ann): float
    {
        if ($exact === []) {
            return 1.0;
        }

        return count(array_intersect($exact, $ann)) / count($exact);
    }

    public function requiresFallback(float $recall): bool
    {
        if ($recall >= 1.0) {
            return false;
        }
        if ($this->fallback === 'reject') {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'ann_recall_insufficient');
        }

        return true;
    }
}
