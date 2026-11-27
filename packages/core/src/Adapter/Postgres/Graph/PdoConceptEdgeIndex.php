<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Graph;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{ChunkId, ConceptEdge, LockMode, TenantId, Ulid, ValueGuard};
use KnowKit\Port\ConceptEdgeIndex;

/** PostgreSQL 17 adapter for snapshot-scoped concept_edges. */
final readonly class PdoConceptEdgeIndex implements ConceptEdgeIndex
{
    /** Eight bound columns. 512 stays under PostgreSQL's 65535-parameter limit. */
    private const INSERT_BATCH = 512;
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    /** @param list<ConceptEdge> $edges */
    public function putEdges(Ulid $tenantId, Ulid $kbId, Ulid $snapshotId, array $edges): void
    {
        ValueGuard::objects($edges, ConceptEdge::class, 8192);
        if ($edges === []) {
            return;
        }

        $tenant = $tenantId instanceof TenantId ? $tenantId : new TenantId($tenantId->value);
        $this->tx->run(function () use ($tenant, $kbId, $snapshotId, $edges): void {
            $this->tx->lockTenant($tenant, LockMode::Update);
            $table = $this->db->table('concept_edges');
            foreach (array_chunk($edges, self::INSERT_BATCH) as $batch) {
                $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $params = [];
                foreach ($batch as $edge) {
                    $params[] = $tenant->value;
                    $params[] = $kbId->value;
                    $params[] = $snapshotId->value;
                    $params[] = $edge->fromChunkId->value;
                    $params[] = $edge->toChunkId->value;
                    $params[] = $edge->relation;
                    $params[] = $edge->weightBps;
                    $params[] = $edge->origin;
                }
                $this->db->execute(
                    'INSERT INTO ' . $table .
                    ' (tenant_id, kb_id, snapshot_id, from_chunk_id, to_chunk_id, relation, weight_bps, origin)' .
                    ' VALUES ' . $placeholders .
                    ' ON CONFLICT (tenant_id, kb_id, snapshot_id, from_chunk_id, relation, to_chunk_id) DO NOTHING',
                    $params,
                );
            }
        });
    }

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
    ): array {
        ValueGuard::objects($fromChunkIds, Ulid::class, 512);
        ValueGuard::strings($allowedRelations, unique: true, codes: true);
        if ($limit < 1 || $limit > 1024) {
            throw new \InvalidArgumentException('limit must be integer 1..1024');
        }
        if ($fromChunkIds === [] || $allowedRelations === []) {
            return [];
        }

        $fromPlaceholders = implode(', ', array_fill(0, count($fromChunkIds), '?'));
        $relPlaceholders = implode(', ', array_fill(0, count($allowedRelations), '?'));
        $sql = 'SELECT from_chunk_id, to_chunk_id, relation, weight_bps, origin'
            . ' FROM ' . $this->db->table('concept_edges')
            . ' WHERE tenant_id = ? AND kb_id = ? AND snapshot_id = ?'
            . ' AND from_chunk_id IN (' . $fromPlaceholders . ')'
            . ' AND relation IN (' . $relPlaceholders . ')'
            . ' ORDER BY weight_bps DESC, from_chunk_id ASC, relation ASC, to_chunk_id ASC'
            . ' LIMIT ?';

        $params = [
            $tenantId->value,
            $kbId->value,
            $snapshotId->value,
            ...array_map(static fn (Ulid $id): string => $id->value, $fromChunkIds),
            ...$allowedRelations,
            $limit,
        ];

        $rows = $this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new ConceptEdge(
                new ChunkId((string) $row['from_chunk_id']),
                new ChunkId((string) $row['to_chunk_id']),
                (string) $row['relation'],
                (int) $row['weight_bps'],
                (string) $row['origin'],
            );
        }

        return $result;
    }
}
