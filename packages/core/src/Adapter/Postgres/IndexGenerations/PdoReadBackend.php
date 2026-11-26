<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\IndexGenerations;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, LiteralQuery, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, StrictJson};
use KnowKit\Port\GenerationReadBackend;

/** PostgreSQL FTS full reload and deterministic literal queries, without query embeddings. */
final readonly class PdoReadBackend implements GenerationReadBackend
{
    public function __construct(private Connection $db, private CanonicalChunkSets $canonical) {}

    public function reload(D\IndexManifest $manifest): D\PersistedIndexRead
    {
        $wire = $manifest->toWire();
        $tenant = new D\TenantId((string) $wire->get('tenantId'));
        $id = $wire->get('generationId');
        $g = $this->db->row(
            'SELECT * FROM ' . $this->db->table('index_generations') . ' WHERE tenant_id=? AND kb_id=? AND id=?',
            [$tenant->value, $wire->get('kbId'), $id],
        );
        if ($g === null) {
            self::fail();
        }
        if (
            $g['profile_id'] !== PdoLexicalIndex::PROFILE
            || $wire->get('profile')->get('id') !== PdoLexicalIndex::PROFILE
            || $wire->get('profile')->get('kind') !== 'lexical'
            || $wire->get('embeddingSpaceDigest') !== null
            || $g['embedding_space_digest'] !== null
        ) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'profile_incompatible');
        }
        if (
            !in_array($g['state'], ['building', 'sealed', 'active', 'retired'], true)
            || $g['manifest_digest'] !== HashDomain::IndexManifest->digest($wire)
            || Jcs::canonicalize(StrictJson::decode((string) $g['manifest_json'])) !== $manifest->canonical()
        ) {
            self::fail();
        }
        $rows = $this->db->execute(
            'SELECT i.*,l.source_id,l.source_version_id lexical_version,l.text_digest FROM ' . $this->db->table('index_memberships') .
            ' i LEFT JOIN ' . $this->db->table('lexical_documents') . ' l ON l.tenant_id=i.tenant_id AND l.generation_id=i.generation_id AND l.chunk_id=i.chunk_id
            WHERE i.tenant_id=? AND i.generation_id=? ORDER BY i.chunk_id',
            [$tenant->value, $id],
        )->fetchAll(\PDO::FETCH_ASSOC);
        $lexicalCount = (int) $this->db->execute(
            'SELECT count(*) FROM ' . $this->db->table('lexical_documents') . ' WHERE tenant_id=? AND generation_id=?',
            [$tenant->value, $id],
        )->fetchColumn();
        if (
            count($rows) !== $wire->get('counts')->get('chunks')
            || $lexicalCount !== count($rows)
            || (int) $g['chunk_count'] !== count($rows)
        ) {
            self::fail();
        }
        $expected = [];
        foreach ($wire->get('members') as $member) {
            $result = $this->canonical->get($tenant, new D\ChunkSetId((string) $member->get('chunkSetId')));
            if ($result->manifestDigest->value !== $member->get('chunkSetDigest')) {
                self::fail();
            }
            foreach ($result->chunkSet->chunks as $chunk) {
                $expected[$chunk->ref->chunkId->value] = $chunk;
            }
        }
        $refs = [];
        foreach ($rows as $row) {
            $chunk = $expected[$row['chunk_id']] ?? null;
            if ($chunk === null) {
                self::fail();
            }
            $ref = $chunk->ref;
            $r = $ref->revisionRef;
            $s = $r->sourceRef;
            if (
                $row['chunk_digest'] !== $ref->chunkDigest->value
                || $row['source_version_id'] !== $s->sourceVersionId->value
                || $row['revision_id'] !== $r->documentRevisionId->value
                || $row['chunk_set_id'] !== $ref->chunkSetId->value
                || $row['source_id'] !== $s->sourceId->value
                || $row['lexical_version'] !== $s->sourceVersionId->value
                || $row['text_digest'] !== $ref->chunkDigest->value
            ) {
                self::fail();
            }
            // Equality covers every stored tsvector/token, including documents with no lexemes.
            // The query uses this document's deterministic canonical text, never a provider call.
            $ok = $this->db->row(
                'SELECT chunk_id FROM ' . $this->db->table('lexical_documents') . " WHERE tenant_id=? AND generation_id=? AND chunk_id=?
                AND simple_tsvector=to_tsvector('pg_catalog.simple',CAST(? AS text))
                AND exact_tokens=ARRAY(SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))
                AND (numnode(plainto_tsquery('pg_catalog.simple',CAST(? AS text)))=0 OR simple_tsvector @@ plainto_tsquery('pg_catalog.simple',CAST(? AS text)))",
                [
                    $tenant->value,
                    $id,
                    $ref->chunkId->value,
                    $chunk->text(),
                    json_encode(LiteralQuery::identifiers($chunk->text()), JSON_THROW_ON_ERROR),
                    $chunk->text(),
                    $chunk->text(),
                ],
            );
            if ($ok === null) {
                self::fail();
            }
            $refs[] = $ref;
        }
        return new D\PersistedIndexRead(new D\ProfileId((string) $g['profile_id']), null, $refs);
    }

    private static function fail(): never
    {
        throw new D\DomainFailure(D\Outcome::Unavailable, 'index_unreadable');
    }
}
