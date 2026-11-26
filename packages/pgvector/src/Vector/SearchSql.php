<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Vector;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\PgVector\Domain\ManifestView;

/** No distance, rank or LIMIT until tenant/snapshot/current eligibility have materialized. */
final class SearchSql
{
    public static function permitted(Connection $db): string
    {
        $snapshots = $db->table('snapshot_memberships');
        $members = $db->table('index_memberships');
        $vectors = $db->table('vector_documents');
        $sources = $db->table('sources');
        $versions = $db->table('source_version_state');
        $originals = $db->table('source_versions');
        $memberships = $db->table('source_memberships');
        $tombstones = $db->table('tombstones');
        $tenants = $db->table('tenants');
        $bases = $db->table('knowledge_bases');

        return <<<SQL
            WITH args AS MATERIALIZED (
                SELECT CAST(? AS char(26)) tenant, CAST(? AS char(26)) kb,
                    CAST(? AS char(26)) snapshot, CAST(? AS char(26)) generation,
                    CAST(? AS jsonb) allowed, CAST(? AS jsonb) collections, CAST(? AS text) space
            ), verified AS MATERIALIZED (
                SELECT value::char(26) chunk_id FROM pg_catalog.jsonb_array_elements_text(CAST(? AS jsonb))
            ), permitted AS MATERIALIZED (
                SELECT v.chunk_id, v.embedding FROM args a
                JOIN $tenants t ON t.id=a.tenant AND t.serving_state='ready'
                JOIN $bases k ON k.tenant_id=t.id AND k.id=a.kb AND k.removed_at IS NULL
                JOIN $snapshots s ON s.tenant_id=a.tenant AND s.kb_id=a.kb
                    AND s.snapshot_id=a.snapshot AND s.generation_id=a.generation
                JOIN $members i ON i.tenant_id=s.tenant_id AND i.generation_id=s.generation_id
                    AND i.source_version_id=s.source_version_id AND i.revision_id=s.revision_id AND i.chunk_set_id=s.chunk_set_id
                JOIN verified x ON x.chunk_id=i.chunk_id
                JOIN $vectors v ON v.tenant_id=i.tenant_id AND v.generation_id=i.generation_id
                    AND v.chunk_id=i.chunk_id AND v.space_digest=a.space
                JOIN $sources src ON src.tenant_id=s.tenant_id AND src.id=s.source_id AND src.lifecycle_state='active'
                JOIN $originals original ON original.tenant_id=s.tenant_id AND original.id=s.source_version_id
                    AND original.source_id=s.source_id
                JOIN $versions sv ON sv.tenant_id=s.tenant_id AND sv.id=s.source_version_id AND sv.usability='usable'
                WHERE s.profile_id='pgvector-l2-v1'
                    AND s.source_version_id IN (SELECT value::char(26) FROM pg_catalog.jsonb_array_elements_text(a.allowed))
                    AND NOT EXISTS (SELECT 1 FROM $tombstones ts WHERE ts.tenant_id=s.tenant_id AND ts.source_id=s.source_id)
                    AND EXISTS (SELECT 1 FROM $memberships m WHERE m.tenant_id=s.tenant_id AND m.kb_id=s.kb_id
                        AND m.source_id=s.source_id AND (a.collections IS NULL OR m.collection_id IN (
                            SELECT value::char(26) FROM pg_catalog.jsonb_array_elements_text(a.collections))))
            ) SELECT chunk_id, embedding FROM permitted
            SQL;
    }

    public static function values(D\AuthorizedSearchScope $scope, D\Query $query, ManifestView $view): array
    {
        $allowed = array_map(static fn (D\SourceVersionId $id): string => $id->value, $scope->allowedSourceVersionIds);
        if ($query->sourceVersionIds !== null) {
            $allowed = array_values(array_intersect($allowed,
                array_map(static fn (D\SourceVersionId $id): string => $id->value, $query->sourceVersionIds)));
        }
        $collections = $query->collectionIds === null ? null : json_encode(
            array_map(static fn (D\CollectionId $id): string => $id->value, $query->collectionIds), JSON_THROW_ON_ERROR);

        return [$scope->tenantId->value, $scope->kbId->value, $scope->snapshotId->value, $scope->generationId->value,
            json_encode($allowed, JSON_THROW_ON_ERROR), $collections, $view->fingerprint->value,
            json_encode(array_map(static fn (D\ChunkRef $ref): string => $ref->chunkId->value, $view->refs()), JSON_THROW_ON_ERROR)];
    }

    public static function exact(Connection $db): string
    {
        $permitted = self::permitted($db);

        return <<<SQL
            WITH corpus AS MATERIALIZED ($permitted)
            SELECT chunk_id, embedding OPERATOR(public.<->) CAST(? AS public.vector) AS distance,
                count(*) OVER () AS permitted_count
            FROM corpus ORDER BY distance, chunk_id COLLATE "C" LIMIT CAST(? AS integer)
            SQL;
    }
}
