<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Scope, CandidateList, RerankProfile, CandidateSubset};

interface Reranker
{
    /** Separate fresh egress check; result cannot add IDs, source refs or expanded spans. */
    public function rerank(Scope $scope, CandidateList $candidates, RerankProfile $profile): CandidateSubset;
}
