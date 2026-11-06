<?php
declare(strict_types=1);

namespace KnowKit\Application;

use KnowKit\Domain\{Scope, Query, Limit, RetrievalOutcome};

/** Public use-case contract; delivery adapters and persistence implementations belong to their owning items. */
interface Retrieval
{
    public function retrieve(Scope $scope, Query $query, Limit $limit): RetrievalOutcome;
}
