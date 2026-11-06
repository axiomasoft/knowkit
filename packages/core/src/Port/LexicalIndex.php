<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{GenerationId, ChunkSet, IndexReceipt, AuthorizedSearchScope, Query, CandidateList};

interface LexicalIndex
{
    public function index(GenerationId $generationId, ChunkSet $chunkSet): IndexReceipt;
    /** Enforce tenant/snapshot/allowed versions in backend membership BEFORE ORDER BY/LIMIT. */
    public function search(AuthorizedSearchScope $scope, Query $query, int $k): CandidateList;
}
