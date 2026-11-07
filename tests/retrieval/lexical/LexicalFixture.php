<?php
declare(strict_types=1);
namespace KnowKit\Tests\Retrieval\Lexical;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Catalog\{ChunkMetadata, ChunkSetMetadata};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Assert;

/** Synthetic sealed generations built by the actual adapter against the migrated PostgreSQL schema. */
final class LexicalFixture
{
    public array $sets = [];
    public array $data = [];
    public array $receipts = [];
    public array $manifests = [];
    public PdoLexicalIndex $index;
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function id(int $n): string { return PortFixture::id($n); }
    public static function corpus(): array { return json_decode(file_get_contents(self::root() . '/tests/fixtures/retrieval/lexical/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR); }
    public static function run(callable $test, bool $seal = true, bool $rogue = false): void
    {
        CatalogFixture::run(function(CatalogFixture $catalog) use ($test, $seal, $rogue): void {
            $fixture = new self($catalog); $fixture->seed();
            if ($rogue) { $fixture->rogue(); }
            if ($seal) { $fixture->seal(); }
            $test($fixture);
        });
    }
    public function __construct(public CatalogFixture $catalog) {}
    public function seed(): void
    {
        $f = $this->catalog; $db = $f->db;
        foreach ([1, 2] as $t) {
            $f->tenant($t); $f->kb($t);
            $db->execute('INSERT INTO ' . $db->table('index_generations') . " (tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,?,'building')",
                [self::id($t), self::id(14), self::id(100), PdoLexicalIndex::PROFILE]);
        }
        foreach (self::corpus()['documents'] as $doc) {
            $t = $doc['tenant']; $n = $doc['number']; $tenant = self::id($t); $source = self::id(1000+$n);
            $f->source(1000+$n, $t); $m = StrictJson::decode(json_encode($doc['chunkSetManifest'], JSON_THROW_ON_ERROR));
            $r = $m->get('revisionRef');
            $sourceRef = new D\SourceVersionRef(new D\TenantId($tenant), new D\SourceId($source), new D\SourceVersionId($r->get('sourceVersionId')), new D\SourceDigest($r->get('sourceDigest')));
            $revision = new D\DocumentRevisionRef($sourceRef, new D\DocumentRevisionId($r->get('documentRevisionId')), new D\IrDigest($r->get('irDigest')), PortFixture::signature($r->get('parserSignature')));
            $span = new D\Span(D\TextLocator::segment(new D\LocalId('s1'), new D\LocalId('b1'), 0, strlen($doc['text'])), new D\Sha256($doc['textDigest']));
            $chunk = new D\Chunk(new D\ChunkRef($revision, new D\ChunkSetId($m->get('chunkSetId')), new D\ChunkId(self::id(6000+$n)), new D\Sha256($doc['textDigest'])),
                new D\DocumentId($m->get('documentId')), [$span], $doc['text']);
            $result = new D\ChunkingResult(new D\ChunkSet($revision, $chunk->ref->chunkSetId, PortFixture::signature($m->get('chunkerSignature')), [$chunk]), $m);
            Assert::assertSame($doc['chunkSetDigest'], $result->manifestDigest->value);
            $ir = new D\IrDocument(StrictJson::decode(json_encode($doc['ir'], JSON_THROW_ON_ERROR)), PortFixture::validator());
            Assert::assertSame($revision->irDigest->value, hash('sha256', $ir->canonical()));
            $this->sets[$doc['name']] = $result; $this->data[$doc['name']] = $doc;
            foreach ([0 => $doc['text'], 1 => $ir->canonical()] as $offset => $bytes) {
                $db->execute('INSERT INTO ' . $db->table('resource_ledger') . " (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
                    VALUES (?,?,?,'owned','test',?,?,?,?, 'accepted')",
                    [$tenant, self::id(7000+10*$n+$offset), $source, 'fixture-'.$n.'-'.$offset, 'v1', hash('sha256', $bytes), strlen($bytes)]);
            }
            $db->execute('INSERT INTO ' . $db->table('source_versions') . ' (tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)', [$tenant, $sourceRef->sourceVersionId->value, $source, self::id(7000+10*$n), $doc['textDigest'], strlen($doc['text']), 'text/plain', 'synthetic', str_repeat('a',64), '2026-11-07T00:00:00Z']);
            $db->execute('INSERT INTO ' . $db->table('document_revisions') . ' (tenant_id,id,source_version_id,parser_name,parser_version,profile_digest,ir_schema,ir_digest,ir_resource_id,coverage_state,coverage_json,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$tenant, $revision->documentRevisionId->value, $sourceRef->sourceVersionId->value, $revision->parserSignature->name, '1', str_repeat('0',64), 'ir-1.0', $revision->irDigest->value, self::id(7001+10*$n), 'complete', Jcs::canonicalize($ir->toWire()->get('coverage')), '2026-11-07T00:00:00Z']);
            $db->execute('INSERT INTO ' . $db->table('documents') . ' VALUES (?,?,?,?)', [$tenant, $chunk->documentId->value, $revision->documentRevisionId->value, 'document']);
            $db->execute('INSERT INTO ' . $db->table('segments') . ' (tenant_id,id,document_id,local_segment_id,text_resource_id,text_digest,status,logical_order) VALUES (?,?,?,?,?,?,?,0)',
                [$tenant, self::id(8000+$n), $chunk->documentId->value, 's1', self::id(7000+10*$n), $doc['textDigest'], 'ok']);
            $db->execute('INSERT INTO ' . $db->table('blocks') . ' (tenant_id,id,revision_id,document_id,local_block_id,kind,origin,provenance_json,reading_order,spans_json,structure_json)
                VALUES (?,?,?,?,?,?,?,?,0,?,?)', [$tenant, self::id(9000+$n), $revision->documentRevisionId->value, $chunk->documentId->value, 'b1', 'text', 'source', '{"kind":"verbatim","supportingRefs":[]}', Jcs::canonicalize([$span->locator->toWire()]), '{}']);
            $f->tx->run(function() use ($f, $result, $chunk, $n, $sourceRef): void {
                $f->tx->lockTenant($sourceRef->tenantId, D\LockMode::Update);
                $f->repo->insertChunkSet($sourceRef->tenantId, $result->chunkSet, new ChunkSetMetadata($result->manifestDigest,
                    [$chunk->ref->chunkId->value => new ChunkMetadata(new D\ResourceId(self::id(7000+10*$n)), new JsonObject(['kind'=>'verbatim']), [])]));
            });
        }
        $this->index = new PdoLexicalIndex($db, $f->tx, PortFixture::validator(), new CanonicalChunkSets(array_values($this->sets)), $f->ids);
        foreach ($this->sets as $name => $result) {
            $this->receipts[$name] = $this->indexSet($result->chunkSet);
        }
        $this->prepareSnapshots();
    }
    public function indexSet(D\ChunkSet $set): D\IndexReceipt
    {
        $f = $this->catalog;
        return $f->tx->run(function() use ($f, $set): D\IndexReceipt {
            $f->tx->lockTenant($set->revisionRef->sourceRef->tenantId, D\LockMode::Update);
            return $this->index->index(new D\GenerationId(self::id(14)), $set);
        });
    }
    public function seal(): void
    {
        $db = $this->catalog->db;
        foreach ([1, 2] as $t) {
            $members = [];
            foreach ($this->sets as $set) {
                $s = $set->chunkSet; $r = $s->revisionRef; $v = $r->sourceRef;
                if ($v->tenantId->value !== self::id($t)) { continue; }
                $members[] = new JsonObject(['sourceId'=>$v->sourceId->value,'sourceVersionId'=>$v->sourceVersionId->value,'sourceDigest'=>$v->sourceDigest->value,
                    'documentRevisionId'=>$r->documentRevisionId->value,'irDigest'=>$r->irDigest->value,'chunkSetId'=>$s->chunkSetId->value,'chunkSetDigest'=>$set->manifestDigest->value,'chunkCount'=>1]);
                if ($v->sourceId->value !== self::id(1003)) {
                    $db->execute('INSERT INTO ' . $db->table('snapshot_memberships') . ' (tenant_id,id,snapshot_id,kb_id,source_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?)', [self::id($t), $this->catalog->ids->next()->value, self::id(110), self::id(100), $v->sourceId->value, $v->sourceVersionId->value, $r->documentRevisionId->value, $s->chunkSetId->value, self::id(14), PdoLexicalIndex::PROFILE]);
                }
            }
            $signature = $this->sets[$t===1?'allowed':'foreign']->chunkSet->chunkerSignature->toWire();
            $wire = new JsonObject(['schemaVersion'=>'index-manifest-1.0','tenantId'=>self::id($t),'kbId'=>self::id(100),'generationId'=>self::id(14),
                'profile'=>new JsonObject(['id'=>PdoLexicalIndex::PROFILE,'kind'=>'lexical','parserSignature'=>$signature,'chunkerSignature'=>$signature]),
                'members'=>$members,'resources'=>[],'coverage'=>StrictJson::decode(json_encode($this->data['allowed']['ir']['coverage'],JSON_THROW_ON_ERROR)),
                'counts'=>new JsonObject(['members'=>count($members),'chunks'=>count($members),'resources'=>0]),'embeddingSpaceDigest'=>null]);
            $manifest = new D\IndexManifest($wire, PortFixture::validator()); $this->manifests[$t] = $manifest;
            $db->execute('UPDATE ' . $db->table('index_generations') . " SET manifest_json=?,manifest_digest=?,state='sealed' WHERE tenant_id=? AND id=?",
                [$manifest->canonical(), HashDomain::IndexManifest->digest($wire), self::id($t), self::id(14)]);
            $db->execute('UPDATE ' . $db->table('publication_snapshots') . ' SET sealed=true WHERE tenant_id=? AND id=?', [self::id($t), self::id(110)]);
        }
    }
    public function prepareSnapshots(): void
    {
        $db = $this->catalog->db;
        foreach ([1, 2] as $t) {
            $db->execute('INSERT INTO ' . $db->table('publication_snapshots') . " VALUES (?,?,?,?,?,false)", [self::id($t),self::id(110),self::id(100),str_repeat('b',64),'2026-11-07T00:00:00Z']);
            $db->execute('INSERT INTO ' . $db->table('snapshot_generations') . ' VALUES (?,?,?,?,?)', [self::id($t),self::id(110),self::id(100),self::id(14),PdoLexicalIndex::PROFILE]);
        }
    }
    public function scope(?array $names = null, int $t = 1, ?D\Query $query = null): D\AuthorizedSearchScope
    {
        $names ??= array_keys(array_filter($this->data, static fn(array $d): bool => $d['tenant']===$t && $d['name']!=='denied'));
        $eligible = array_map(fn(string $n): D\SourceVersionId => $this->sets[$n]->chunkSet->revisionRef->sourceRef->sourceVersionId, $names);
        $scope = new D\Scope(new D\TenantId(self::id($t)), new D\KbId(self::id(100)), new D\ActorId(self::id(101)), 'lesson.read', new D\Decimal('0'), new D\Sha256(str_repeat('a',64)));
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[], 'allowedSourceVersionIds'=>array_map(static fn($id): string => $id->value,$eligible),
            'grantEpoch'=>'0','policyBindingDigest'=>str_repeat('c',64),'expiresAt'=>'2026-11-07T00:00:30Z']), PortFixture::validator());
        return D\AuthorizedSearchScope::materialize($scope,$grant,new D\SnapshotRef($scope->tenantId,$scope->kbId,new D\SnapshotId(self::id(110)),new D\Fingerprint(str_repeat('b',64))),
            new D\GenerationId(self::id(14)),$eligible,new D\UtcInstant('2026-11-07T00:00:01Z'),null,$query);
    }
    public function names(D\CandidateList $list): array
    {
        return array_map(function(D\Candidate $c): string {
            foreach ($this->sets as $name => $set) { if ($set->chunkSet->chunks[0]->ref == $c->ref) { return $name; } }
            throw new \LogicException('Unknown candidate escaped manifest');
        }, $list->candidates);
    }
    public static function failure(callable $run, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try { $run(); Assert::fail('Forbidden input accepted'); }
        catch (D\DomainFailure $error) { Assert::assertSame($code,$error->errorCode); Assert::assertSame($outcome,$error->outcome); }
    }
    private function rogue(): void
    {
        $db = $this->catalog->db; $t = self::id(1); $rogue = self::id(6999);
        $db->execute('INSERT INTO ' . $db->table('chunks') . ' SELECT tenant_id,?,chunk_set_id,content_digest,document_id,spans_json,provenance_json,byte_length,structure_path,canonical_payload_ref FROM ' . $db->table('chunks') . ' WHERE tenant_id=? AND id=?', [$rogue,$t,self::id(6001)]);
        $db->execute('INSERT INTO ' . $db->table('index_memberships') . ' SELECT tenant_id,?,generation_id,?,chunk_digest,source_version_id,revision_id,chunk_set_id FROM ' . $db->table('index_memberships') . ' WHERE tenant_id=? AND chunk_id=?', [$this->catalog->ids->next()->value,$rogue,$t,self::id(6001)]);
        $db->execute('INSERT INTO ' . $db->table('lexical_documents') . " SELECT tenant_id,?,generation_id,?,source_id,source_version_id,to_tsvector('simple',repeat('gravity ',100)),exact_tokens,text_digest FROM " . $db->table('lexical_documents') . ' WHERE tenant_id=? AND chunk_id=?', [$this->catalog->ids->next()->value,$rogue,$t,self::id(6001)]);
    }
}
