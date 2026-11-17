<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Lexical;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Port\{IdGenerator, LexicalIndex};

/** PostgreSQL FTS simple v1. Immutable resolution/deterministic order adapt AgenTools Service;
 * positive-only matches adapt DeepTutor _MatchedOnlyRetriever (f7aed074), Apache-2.0;
 * license and notices: docs/architecture/third-party/DeepTutor-{LICENSE.txt,THIRD_PARTY_NOTICES.md}.
 * Modified for SQL isolation and mandatory failure semantics. No donor runtime dependency.
 * Provenance: tests/retrieval/lexical/evidence/P2.8-reuse.json.
 */
final readonly class PdoLexicalIndex implements LexicalIndex
{
    public const PROFILE = 'postgres-fts-simple-v1';
    private SealedManifest $manifests;
    public function __construct(private Connection $db, private PdoCatalogTransaction $transaction,
        private ContractValidator $validator, private CanonicalChunkSets $canonical, private IdGenerator $ids)
    {
        if ($transaction->db !== $db) { throw new \InvalidArgumentException('One bound catalog connection required'); }
        $this->manifests = new SealedManifest();
    }

    /** Caller owns transaction and tenant update guard; buffers are loaded before entering it.
     * Receipt binds the indexed chunkset manifest, available before generation sealing.
     */
    public function index(D\GenerationId $generationId, D\ChunkSet $chunkSet): D\IndexReceipt
    {
        $source = $chunkSet->revisionRef->sourceRef; $tenant = $source->tenantId;
        $this->transaction->requireTenant($tenant, true);
        $canonical = $this->canonical->get($tenant, $chunkSet->chunkSetId);
        if (!self::sameChunkSet($canonical->chunkSet, $chunkSet)) { self::invalid('immutable_conflict'); }
        $g = $this->db->row('SELECT state,profile_id,embedding_space_digest FROM ' . $this->db->table('index_generations') .
            ' WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant->value, $generationId->value]);
        if ($g === null) { self::invalid('generation_missing'); }
        if ($g['state'] !== 'building') { self::invalid('generation_sealed'); }
        if ($g['profile_id'] !== self::PROFILE || $g['embedding_space_digest'] !== null) { self::invalid('profile_incompatible'); }
        $set = $this->db->row('SELECT cs.manifest_digest,cs.chunker_signature,r.*,v.source_id,v.source_digest FROM ' . $this->db->table('chunk_sets') .
            ' cs JOIN ' . $this->db->table('document_revisions') . ' r ON r.tenant_id=cs.tenant_id AND r.id=cs.revision_id JOIN ' .
            $this->db->table('source_versions') . ' v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id WHERE cs.tenant_id=? AND cs.id=?',
            [$tenant->value, $chunkSet->chunkSetId->value]);
        $r = $chunkSet->revisionRef;
        if ($set === null || $set['manifest_digest'] !== $canonical->manifestDigest->value
            || Jcs::canonicalize(StrictJson::decode($set['chunker_signature'])) !== Jcs::canonicalize($chunkSet->chunkerSignature->toWire())
            || $set['id'] !== $r->documentRevisionId->value || $set['ir_digest'] !== $r->irDigest->value
            || $set['source_id'] !== $source->sourceId->value || $set['source_version_id'] !== $source->sourceVersionId->value
            || $set['source_digest'] !== $source->sourceDigest->value || $set['parser_name'] !== $r->parserSignature->name
            || $set['parser_version'] !== $r->parserSignature->version || $set['profile_digest'] !== $r->parserSignature->profileDigest->value) { self::invalid(); }
        $rows = $this->db->execute('SELECT c.*,b.digest payload_digest,b.byte_length payload_length,b.state payload_state,b.source_id payload_source FROM ' .
            $this->db->table('chunks') . ' c JOIN ' . $this->db->table('resource_ledger') .
            ' b ON b.tenant_id=c.tenant_id AND b.id=c.canonical_payload_ref WHERE c.tenant_id=? AND c.chunk_set_id=? ORDER BY c.id',
            [$tenant->value, $chunkSet->chunkSetId->value])->fetchAll(\PDO::FETCH_ASSOC);
        $persisted = array_column($rows, null, 'id');
        if (count($persisted) !== count($chunkSet->chunks)) { self::invalid(); }
        foreach ($chunkSet->chunks as $chunk) {
            $row = $persisted[$chunk->ref->chunkId->value] ?? null;
            $spans = array_map(static fn(D\Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value), $chunk->spans);
            if ($row === null || $row['content_digest'] !== $chunk->ref->chunkDigest->value || $row['document_id'] !== $chunk->documentId->value
                || (int) $row['byte_length'] !== strlen($chunk->text()) || $row['payload_digest'] !== $chunk->ref->chunkDigest->value
                || (int) $row['payload_length'] !== strlen($chunk->text()) || $row['payload_state'] !== 'accepted'
                || $row['payload_source'] !== $source->sourceId->value
                || Jcs::canonicalize(StrictJson::decode($row['spans_json'])) !== Jcs::canonicalize($spans)) { self::invalid(); }
        }
        foreach ($chunkSet->chunks as $chunk) { $this->writeChunk($generationId, $chunk); }
        $this->db->execute('UPDATE ' . $this->db->table('index_generations') . ' g SET chunk_count=(SELECT count(*) FROM ' .
            $this->db->table('index_memberships') . ' i WHERE i.tenant_id=g.tenant_id AND i.generation_id=g.id) WHERE g.tenant_id=? AND g.id=?',
            [$tenant->value, $generationId->value]);
        return new D\IndexReceipt($tenant, $generationId, $chunkSet->chunkSetId, $canonical->manifestDigest, count($chunkSet->chunks));
    }
    private function writeChunk(D\GenerationId $generation, D\Chunk $chunk): void
    {
        $ref = $chunk->ref; $source = $ref->revisionRef->sourceRef;
        $values = [$source->tenantId->value, $generation->value, $ref->chunkId->value];
        $identity = [$ref->chunkDigest->value, $source->sourceVersionId->value, $ref->revisionRef->documentRevisionId->value, $ref->chunkSetId->value];
        $i = $this->db->table('index_memberships'); $l = $this->db->table('lexical_documents');
        $this->db->execute("INSERT INTO $i (id,tenant_id,generation_id,chunk_id,chunk_digest,source_version_id,revision_id,chunk_set_id)
            VALUES (?,?,?,?,?,?,?,?) ON CONFLICT (tenant_id,generation_id,chunk_id) DO NOTHING", [$this->ids->next()->value, ...$values, ...$identity]);
        if ($this->db->row("SELECT id FROM $i WHERE tenant_id=? AND generation_id=? AND chunk_id=? AND chunk_digest=? AND source_version_id=? AND revision_id=? AND chunk_set_id=?",
            [...$values, ...$identity]) === null) { self::invalid('immutable_conflict'); }
        $tokens = json_encode(LiteralQuery::identifiers($chunk->text()), JSON_THROW_ON_ERROR);
        $this->db->execute("INSERT INTO $l (id,tenant_id,generation_id,chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest)
            VALUES (?,?,?,?,?,?,to_tsvector('pg_catalog.simple',CAST(? AS text)),ARRAY(SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb))),?)
            ON CONFLICT (tenant_id,generation_id,chunk_id) DO NOTHING",
            [$this->ids->next()->value, ...$values, $source->sourceId->value, $source->sourceVersionId->value, $chunk->text(), $tokens, $ref->chunkDigest->value]);
        if ($this->db->row("SELECT id FROM $l WHERE tenant_id=? AND generation_id=? AND chunk_id=? AND source_id=? AND source_version_id=?
            AND text_digest=? AND simple_tsvector=to_tsvector('pg_catalog.simple',CAST(? AS text))
            AND exact_tokens=ARRAY(SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))",
            [...$values, $source->sourceId->value, $source->sourceVersionId->value, $ref->chunkDigest->value, $chunk->text(), $tokens]) === null) { self::invalid('immutable_conflict'); }
    }

    public function search(D\AuthorizedSearchScope $scope, D\Query $query, int $k): D\CandidateList
    {
        if ($k < 1 || $k > 512) { throw new \InvalidArgumentException('Top-k must be 1..512'); }
        $manifest = $this->manifest($scope);
        $allowed = array_map(static fn(D\SourceVersionId $id): string => $id->value, $scope->allowedSourceVersionIds);
        if ($query->sourceVersionIds !== null) {
            $allowed = array_values(array_intersect($allowed, array_map(static fn(D\SourceVersionId $id): string => $id->value, $query->sourceVersionIds)));
        }
        $plan = new LiteralQuery($query->text()); $verified = []; $refs = [];
        foreach ($manifest->get('members') as $member) {
            if (!in_array($member->get('sourceVersionId'), $allowed, true)) { continue; }
            $result = $this->canonical->get($scope->tenantId, new D\ChunkSetId($member->get('chunkSetId')));
            $set = $result->chunkSet; $revision = $set->revisionRef; $source = $revision->sourceRef;
            if ($member->get('sourceId') !== $source->sourceId->value || $member->get('sourceVersionId') !== $source->sourceVersionId->value
                || $member->get('sourceDigest') !== $source->sourceDigest->value || $member->get('documentRevisionId') !== $revision->documentRevisionId->value
                || $member->get('irDigest') !== $revision->irDigest->value || $member->get('chunkSetDigest') !== $result->manifestDigest->value
                || $member->get('chunkCount') !== count($set->chunks)
                || Jcs::canonicalize($manifest->get('profile')->get('parserSignature')) !== Jcs::canonicalize($revision->parserSignature->toWire())
                || Jcs::canonicalize($manifest->get('profile')->get('chunkerSignature')) !== Jcs::canonicalize($set->chunkerSignature->toWire())) { self::invalid(); }
            foreach ($set->chunks as $chunk) {
                $id = $chunk->ref->chunkId->value;
                if (isset($refs[$id])) { self::invalid(); }
                $scope->assertCandidate($chunk->ref); $refs[$id] = $chunk->ref;
                $verified[] = ['source_id' => $source->sourceId->value, 'source_version_id' => $source->sourceVersionId->value,
                    'revision_id' => $revision->documentRevisionId->value, 'chunk_set_id' => $set->chunkSetId->value,
                    'chunk_id' => $id, 'chunk_digest' => $chunk->ref->chunkDigest->value, 'source_digest' => $source->sourceDigest->value,
                    'ir_digest' => $revision->irDigest->value, 'chunk_set_digest' => $result->manifestDigest->value,
                    'literal_match' => $plan->matchesCanonical($chunk->text())];
            }
        }
        $collections = $query->collectionIds === null ? null : json_encode(array_map(static fn(D\CollectionId $id): string => $id->value, $query->collectionIds), JSON_THROW_ON_ERROR);
        $values = [$scope->tenantId->value, $scope->kbId->value, $scope->snapshotId->value, $scope->generationId->value,
            json_encode($allowed, JSON_THROW_ON_ERROR), $collections, json_encode($plan->identifiers, JSON_THROW_ON_ERROR),
            json_encode($plan->ftsPhrases(), JSON_THROW_ON_ERROR), $plan->lexical, $plan->matchNone, json_encode($verified, JSON_THROW_ON_ERROR), $k];
        $rows = $this->db->execute(SearchSql::select($this->db), $values)->fetchAll(\PDO::FETCH_ASSOC);
        $candidates = [];
        foreach ($rows as $row) {
            // Defense in depth after the SQL manifest join; never refill an invalid backend response.
            $ref = $refs[$row['chunk_id']] ?? null;
            if ($ref === null || !is_numeric($row['score']) || (float) $row['score'] <= 0) { self::invalid('adapter_contract_violation'); }
            $candidates[] = new D\Candidate($ref, count($candidates) + 1, (float) $row['score']);
        }
        return new D\CandidateList($scope, $candidates);
    }
    private function manifest(D\AuthorizedSearchScope $scope): JsonObject
    {
        $row = $this->db->row('SELECT g.* FROM ' . $this->db->table('index_generations') . ' g JOIN ' .
            $this->db->table('snapshot_generations') . ' sg ON sg.tenant_id=g.tenant_id AND sg.kb_id=g.kb_id AND sg.generation_id=g.id AND sg.profile_id=g.profile_id JOIN ' .
            $this->db->table('publication_snapshots') . ' s ON s.tenant_id=sg.tenant_id AND s.kb_id=sg.kb_id AND s.id=sg.snapshot_id
            WHERE g.tenant_id=? AND g.kb_id=? AND g.id=? AND s.id=? AND s.sealed AND g.state IN (\'sealed\',\'active\',\'retired\')',
            [$scope->tenantId->value, $scope->kbId->value, $scope->generationId->value, $scope->snapshotId->value]);
        if ($row === null) { throw new D\DomainFailure(D\Outcome::Unavailable, 'sealed_generation_unavailable'); }
        try {
            // Use the index-manifest schema's 16 MiB ceiling, not the generic
            // 1 MiB decoder default. Keep full schema/semantic validation.
            $manifest = $this->manifests->load(bytes: $row['manifest_json'], expectedDigest: $row['manifest_digest'], validator: $this->validator);
        }
        catch (\InvalidArgumentException | D\Contract\ContractViolation $error) { self::invalid(); }
        if ($row['profile_id'] !== self::PROFILE || $row['embedding_space_digest'] !== null || $manifest->get('profile')->get('kind') !== 'lexical'
            || $manifest->get('profile')->get('id') !== self::PROFILE || $manifest->get('embeddingSpaceDigest') !== null) { self::invalid('profile_incompatible'); }
        if ($manifest->get('tenantId') !== $scope->tenantId->value || $manifest->get('kbId') !== $scope->kbId->value
            || $manifest->get('generationId') !== $scope->generationId->value) { self::invalid(); }
        return $manifest;
    }
    private static function sameChunkSet(D\ChunkSet $left, D\ChunkSet $right): bool
    {
        if (
            Jcs::canonicalize($left->revisionRef->toWire()) !== Jcs::canonicalize($right->revisionRef->toWire())
            || !$left->chunkSetId->equals($right->chunkSetId)
            || Jcs::canonicalize($left->chunkerSignature->toWire()) !== Jcs::canonicalize($right->chunkerSignature->toWire())
            || count($left->chunks) !== count($right->chunks)
        ) {
            return false;
        }
        foreach ($left->chunks as $index => $chunk) {
            $other = $right->chunks[$index];
            $leftSpans = array_map(
                static fn(D\Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value),
                $chunk->spans,
            );
            $rightSpans = array_map(
                static fn(D\Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value),
                $other->spans,
            );
            if (
                Jcs::canonicalize($chunk->ref->toWire()) !== Jcs::canonicalize($other->ref->toWire())
                || !$chunk->documentId->equals($other->documentId)
                || $chunk->text() !== $other->text()
                || Jcs::canonicalize($leftSpans) !== Jcs::canonicalize($rightSpans)
            ) {
                return false;
            }
        }
        return true;
    }
    private static function invalid(string $code = 'manifest_mismatch'): never { throw new D\DomainFailure(D\Outcome::Incompatible, $code); }
    public function __debugInfo(): array { return ['profile' => self::PROFILE]; }
}
