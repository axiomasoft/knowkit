<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{GenerationId, ChunkSet, EmbeddingSpace, IndexReceipt, AuthorizedSearchScope, Query, CandidateList};

interface VectorIndex
{
    public function index(GenerationId $generationId, ChunkSet $chunkSet, EmbeddingSpace $space): IndexReceipt;
    /** Full space fingerprint mismatch is incompatible; no lexical fallback or postfilter-only isolation. */
    public function search(AuthorizedSearchScope $scope, Query $query, int $k, EmbeddingSpace $space): CandidateList;
}
