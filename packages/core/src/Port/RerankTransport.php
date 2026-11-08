<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Query;
use KnowKit\Domain\RerankDocument;
use KnowKit\Domain\RerankProfile;

/** Optional transport only; this interface does not implement an external adapter. */
interface RerankTransport
{
    /** Explicit registered deployment destination, without credentials. */
    public function destination(RerankProfile $profile): JsonObject;

    /**
     * One transmission, no internal retries. Timeouts must throw a safe typed unavailable failure.
     * @param list<RerankDocument> $documents
     * @return list<JsonObject> Strict {candidateId, score?}; never spans, text or citations.
     */
    public function rerank(Query $query, array $documents, RerankProfile $profile): array;
}
