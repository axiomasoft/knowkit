<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\QueryBinding;

interface ContextPruner
{
    /**
     * @param list<PrunableUnit> $units
     * @return list<PrunedSelection>
     */
    public function prune(
        QueryBinding $query,
        array $units,
        PruningPolicy $policy,
    ): array;
}
