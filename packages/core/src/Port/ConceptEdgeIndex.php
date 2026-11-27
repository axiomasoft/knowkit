<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\ConceptEdge;
use KnowKit\Domain\Ulid;

interface ConceptEdgeIndex
{
    /** @param list<ConceptEdge> $edges */
    public function putEdges(Ulid $tenantId, Ulid $kbId, Ulid $snapshotId, array $edges): void;

    /**
     * @param list<Ulid> $fromChunkIds
     * @param list<string> $allowedRelations
     * @return list<ConceptEdge>
     */
    public function neighbors(
        Ulid $tenantId,
        Ulid $kbId,
        Ulid $snapshotId,
        array $fromChunkIds,
        array $allowedRelations,
        int $limit,
    ): array;
}
