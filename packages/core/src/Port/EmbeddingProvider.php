<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{EmbeddingCapabilities, EmbeddingInput, Result, EmbeddingBatch, EmbeddingAccess, SpaceRef, Vector, QueryInput};

interface EmbeddingProvider
{
    public function capabilities(): EmbeddingCapabilities;
    /**
     * @param list<EmbeddingInput> $inputs
     * @return Result<EmbeddingBatch>
     * Every call obtains fresh processing and egress decisions before sending; access is not a cached grant.
     */
    public function embedDocuments(EmbeddingAccess $access, array $inputs, SpaceRef $space): Result;
    /** @return Result<Vector> Same per-call checks apply to query transmission. */
    public function embedQuery(EmbeddingAccess $access, QueryInput $input, SpaceRef $space): Result;
}
