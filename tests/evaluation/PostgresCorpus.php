<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\Postgres\Catalog\ChunkMetadata;
use KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tooling\Qa;

/** Reuses the migrated real adapter and disclosure harness; only fixture loading is new. */
final readonly class PostgresCorpus
{
    public function __construct(
        private CatalogFixture $catalog,
        private LexicalFixture $lexical,
    ) {}

    public static function run(Corpus $corpus, callable $run): mixed
    {
        return CatalogFixture::run(static function (CatalogFixture $catalog) use ($corpus, $run): mixed {
            $lexical = new LexicalFixture($catalog);
            (new self($catalog, $lexical))->seed($corpus);

            return $run(new DisclosureFixture($lexical));
        });
    }

    private function seed(Corpus $corpus): void
    {
        foreach ([1, 2] as $tenant) {
            $this->catalog->tenant($tenant);
            $this->catalog->kb($tenant);
            $this->insert('index_generations', ['tenant_id' => LexicalFixture::id($tenant),
                'id' => LexicalFixture::id(14), 'kb_id' => LexicalFixture::id(100),
                'profile_id' => PdoLexicalIndex::PROFILE, 'state' => 'building']);
        }
        foreach ($corpus->documents as $doc) {
            $this->seedDocument($doc);
        }
        $this->lexical->index = new PdoLexicalIndex($this->catalog->db, $this->catalog->tx,
            PortFixture::validator(), new CanonicalChunkSets(array_values($this->lexical->sets)), $this->catalog->ids);
        foreach ($this->lexical->sets as $set) {
            $this->lexical->indexSet($set->chunkSet);
        }
        $this->lexical->prepareSnapshots();
        $this->lexical->seal();
    }

    private function seedDocument(array $doc): void
    {
        $m = StrictJson::decode(json_encode($doc['chunkSetManifest'], JSON_THROW_ON_ERROR));
        $r = $m->get('revisionRef');
        $source = new D\SourceVersionRef(new D\TenantId($r->get('tenantId')), new D\SourceId($r->get('sourceId')),
            new D\SourceVersionId($r->get('sourceVersionId')), new D\SourceDigest($r->get('sourceDigest')));
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId($r->get('documentRevisionId')),
            new D\IrDigest($r->get('irDigest')), PortFixture::signature($r->get('parserSignature')));
        $span = $m->get('chunks')[0]->get('spans')[0];
        $locator = $span->get('kind') === 'page'
            ? D\TextLocator::page(new D\LocalId($span->get('pageId')), $span->get('physicalNo'), $span->get('printedLabel'),
                new D\LocalId('b1'), $span->get('byteStart'), $span->get('byteEnd'))
            : D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), $span->get('byteStart'), $span->get('byteEnd'));
        $chunk = new D\Chunk(new D\ChunkRef($revision, new D\ChunkSetId($m->get('chunkSetId')),
            new D\ChunkId($m->get('chunks')[0]->get('chunkId')), new D\Sha256($doc['textDigest'])),
            new D\DocumentId($m->get('documentId')), [new D\Span($locator, new D\Sha256($doc['textDigest']))], $doc['text']);
        $set = new D\ChunkingResult(new D\ChunkSet($revision, $chunk->ref->chunkSetId,
            PortFixture::signature($m->get('chunkerSignature')), [$chunk]), $m);
        Qa::ensure($set->manifestDigest->value === $doc['chunkSetDigest'], 'chunkset digest mismatch');
        $this->lexical->sets[$doc['name']] = $set;
        $this->lexical->data[$doc['name']] = $doc;
        $this->persistDocument($doc, $set);
    }

    private function persistDocument(array $doc, D\ChunkingResult $set): void
    {
        $chunk = $set->chunkSet->chunks[0];
        $r = $chunk->ref->revisionRef;
        $s = $r->sourceRef;
        $n = $doc['number'];
        $sourceNumber = $doc['sourceNumber'] ?? 1000 + $n;
        if ($this->catalog->db->row('SELECT id FROM ' . $this->catalog->db->table('sources') . ' WHERE tenant_id=? AND id=?',
            [$s->tenantId->value, $s->sourceId->value]) === null) {
            $this->catalog->source($sourceNumber, $doc['tenant']);
        }
        $ir = Jcs::canonicalize(StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)));
        foreach ([$doc['text'], $ir] as $offset => $bytes) {
            $this->insert('resource_ledger', ['tenant_id' => $s->tenantId->value, 'id' => LexicalFixture::id(7000 + 10 * $n + $offset),
                'source_id' => $s->sourceId->value, 'owner_kind' => 'owned', 'storage_id' => 'test',
                'storage_key' => "evaluation-{$n}-{$offset}", 'object_version' => 'v1',
                'digest' => hash('sha256', $bytes), 'byte_length' => strlen($bytes), 'state' => 'accepted']);
        }
        $this->insert('source_versions', ['tenant_id' => $s->tenantId->value, 'id' => $s->sourceVersionId->value,
            'source_id' => $s->sourceId->value, 'blob_resource_id' => LexicalFixture::id(7000 + 10 * $n),
            'source_digest' => $s->sourceDigest->value, 'byte_length' => strlen($doc['text']), 'media_type' => 'text/plain',
            'rights_ref' => 'evaluation-v1:approved-project-authored', 'idempotency_fingerprint' => str_repeat('a', 64),
            'created_at' => '2026-11-08T00:00:00Z']);
        $this->insert('document_revisions', ['tenant_id' => $s->tenantId->value, 'id' => $r->documentRevisionId->value,
            'source_version_id' => $s->sourceVersionId->value, 'parser_name' => $r->parserSignature->name,
            'parser_version' => '1', 'profile_digest' => str_repeat('0', 64), 'ir_schema' => 'ir-1.0',
            'ir_digest' => $r->irDigest->value, 'ir_resource_id' => LexicalFixture::id(7001 + 10 * $n),
            'coverage_state' => 'complete', 'coverage_json' => Jcs::canonicalize($doc['ir']['coverage']),
            'created_at' => '2026-11-08T00:00:00Z']);
        $this->persistChunk($doc, $set);
    }

    private function persistChunk(array $doc, D\ChunkingResult $set): void
    {
        $chunk = $set->chunkSet->chunks[0];
        $r = $chunk->ref->revisionRef;
        $tenant = $r->sourceRef->tenantId;
        $this->insert('documents', ['tenant_id' => $tenant->value, 'id' => $chunk->documentId->value,
            'revision_id' => $r->documentRevisionId->value, 'logical_path' => 'document']);
        $page = $chunk->spans[0]->locator->physicalNo();
        $location = ['tenant_id' => $tenant->value, 'id' => LexicalFixture::id(8000 + $doc['number']),
            'document_id' => $chunk->documentId->value, 'text_resource_id' => LexicalFixture::id(7000 + 10 * $doc['number']),
            'text_digest' => $doc['textDigest'], 'status' => 'ok'];
        $location += $page === null ? ['local_segment_id' => 's1', 'logical_order' => 0]
            : ['local_page_id' => 'p1', 'physical_no' => $page, 'printed_label' => 'i'];
        $this->insert($page === null ? 'segments' : 'pages', $location);
        $this->insert('blocks', ['tenant_id' => $tenant->value, 'id' => LexicalFixture::id(9000 + $doc['number']),
            'revision_id' => $r->documentRevisionId->value, 'document_id' => $chunk->documentId->value,
            'local_block_id' => 'b1', 'kind' => 'text', 'origin' => 'source',
            'provenance_json' => '{"kind":"verbatim","supportingRefs":[]}', 'reading_order' => 0,
            'spans_json' => Jcs::canonicalize([$chunk->spans[0]->locator->toWire()]), 'structure_json' => '{}']);
        $this->catalog->tx->run(function () use ($tenant, $set, $chunk, $doc): void {
            $this->catalog->tx->lockTenant($tenant, D\LockMode::Update);
            $metadata = new ChunkSetMetadata($set->manifestDigest, [$chunk->ref->chunkId->value => new ChunkMetadata(
                new D\ResourceId(LexicalFixture::id(7000 + 10 * $doc['number'])), new JsonObject(['kind' => 'verbatim']), [],
            )]);
            $this->catalog->repo->insertChunkSet($tenant, $set->chunkSet, $metadata);
        });
    }

    /** SQL identifiers are fixed in this fixture; all corpus data is bound as values. */
    private function insert(string $table, array $values): void
    {
        $columns = implode(',', array_keys($values));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->catalog->db->execute('INSERT INTO ' . $this->catalog->db->table($table) . " ({$columns}) VALUES ({$placeholders})",
            array_values($values));
    }
}
