<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Lexical;

use KnowKit\Adapter\Postgres\Catalog\Connection;

/** The materialized permitted relation contains exact manifest + snapshot + rights membership.
 * Neither rank nor LIMIT exists inside it. Values, including JSON inventories and LIMIT, are bound.
 */
final class SearchSql
{
    public static function select(Connection $db): string
    {
        $l = $db->table('lexical_documents'); $i = $db->table('index_memberships');
        $s = $db->table('snapshot_memberships'); $c = $db->table('chunks');
        $m = $db->table('source_memberships');
        $cs = $db->table('chunk_sets'); $r = $db->table('document_revisions'); $sv = $db->table('source_versions');
        return <<<SQL
WITH args AS MATERIALIZED (
 SELECT CAST(? AS char(26)) tenant, CAST(? AS char(26)) kb, CAST(? AS char(26)) snapshot,
        CAST(? AS char(26)) generation, CAST(? AS jsonb) allowed, CAST(? AS jsonb) collections,
        CAST(? AS jsonb) identifiers, CAST(? AS jsonb) phrases, CAST(? AS text) lexical,
        CAST(? AS boolean) match_none
), verified AS MATERIALIZED (
 SELECT * FROM jsonb_to_recordset(CAST(? AS jsonb)) AS v(
 source_id text, source_version_id text, revision_id text, chunk_set_id text,
 chunk_id text, chunk_digest text, source_digest text, ir_digest text, chunk_set_digest text, literal_match boolean)
), permitted AS MATERIALIZED (
 SELECT l.chunk_id, l.simple_tsvector, l.exact_tokens, v.literal_match
 FROM args a
 JOIN $s s ON s.tenant_id=a.tenant AND s.kb_id=a.kb AND s.snapshot_id=a.snapshot AND s.generation_id=a.generation
 JOIN $i i ON i.tenant_id=s.tenant_id AND i.generation_id=s.generation_id
   AND i.source_version_id=s.source_version_id AND i.revision_id=s.revision_id AND i.chunk_set_id=s.chunk_set_id
 JOIN verified v ON v.source_id=s.source_id AND v.source_version_id=i.source_version_id
   AND v.revision_id=i.revision_id AND v.chunk_set_id=i.chunk_set_id
   AND v.chunk_id=i.chunk_id AND v.chunk_digest=i.chunk_digest
 JOIN $c c ON c.tenant_id=i.tenant_id AND c.id=i.chunk_id AND c.chunk_set_id=i.chunk_set_id AND c.content_digest=i.chunk_digest
 JOIN $cs cs ON cs.tenant_id=i.tenant_id AND cs.id=i.chunk_set_id AND cs.revision_id=i.revision_id AND cs.manifest_digest=v.chunk_set_digest
 JOIN $r r ON r.tenant_id=i.tenant_id AND r.id=i.revision_id AND r.source_version_id=i.source_version_id AND r.ir_digest=v.ir_digest
 JOIN $sv sv ON sv.tenant_id=i.tenant_id AND sv.id=i.source_version_id AND sv.source_id=s.source_id AND sv.source_digest=v.source_digest
 JOIN $l l ON l.tenant_id=i.tenant_id AND l.generation_id=i.generation_id AND l.chunk_id=i.chunk_id
   AND l.source_id=s.source_id AND l.source_version_id=i.source_version_id AND l.text_digest=i.chunk_digest
 WHERE s.profile_id='postgres-fts-simple-v1'
   AND s.source_version_id IN (SELECT value::char(26) FROM jsonb_array_elements_text(a.allowed))
   AND (a.collections IS NULL OR EXISTS (
     SELECT 1 FROM $m m WHERE m.tenant_id=s.tenant_id AND m.kb_id=s.kb_id AND m.source_id=s.source_id
       AND m.collection_id IN (SELECT value::char(26) FROM jsonb_array_elements_text(a.collections))))
), matched AS MATERIALIZED (
 SELECT p.chunk_id, CASE WHEN jsonb_array_length(a.identifiers)>0 THEN 1 ELSE 0 END exact_match,
   CASE WHEN a.lexical='' THEN 1.0 ELSE ts_rank(p.simple_tsvector,websearch_to_tsquery('pg_catalog.simple',a.lexical)) END score
 FROM permitted p CROSS JOIN args a
 WHERE NOT a.match_none AND p.literal_match
   AND p.exact_tokens @> ARRAY(SELECT value FROM jsonb_array_elements_text(a.identifiers))
   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(a.phrases) AS phrase(value)
     WHERE NOT (p.simple_tsvector @@ phraseto_tsquery('pg_catalog.simple',phrase.value)))
   AND (a.lexical='' OR (p.simple_tsvector @@ websearch_to_tsquery('pg_catalog.simple',a.lexical)
     AND ts_rank(p.simple_tsvector,websearch_to_tsquery('pg_catalog.simple',a.lexical))>0))
)
SELECT chunk_id, score FROM matched ORDER BY exact_match DESC, score DESC, chunk_id COLLATE "C" LIMIT CAST(? AS integer)
SQL;
    }
}
