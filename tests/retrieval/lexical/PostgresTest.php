<?php
declare(strict_types=1);
namespace KnowKit\Tests\Retrieval\Lexical;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, LiteralQuery, PdoLexicalIndex, SearchSql};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Registry\{AdapterDescriptor, AdapterRegistry, Factory};
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:lexical')]
final class PostgresTest extends TestCase
{
    public function testTenantSnapshotAndDeniedCompetitionArePrefilteredWithExplain(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $db = $f->catalog->db; $scope = $f->scope(); $q = new D\Query('gravity');
            self::assertSame(['allowed'], $f->names($f->index->search($scope, $q, 1)));
            self::assertSame(['allowed','tie'], $f->names($f->index->search($scope, $q, 10)));
            self::assertSame(['foreign'], $f->names($f->index->search($f->scope(['foreign'],2),$q,1)));
            self::assertSame([], $f->names($f->index->search($f->scope([]),$q,1)));
            self::assertSame([], $f->names($f->index->search($f->scope(['other-snapshot']),$q,1)));
            // Prove this would fail with unrestricted top-k then postfilter.
            $raw = $db->execute("SELECT tenant_id||':'||chunk_id FROM " . $db->table('lexical_documents') . " WHERE simple_tsvector @@ websearch_to_tsquery('simple','gravity')
                ORDER BY ts_rank(simple_tsvector,websearch_to_tsquery('simple','gravity')) DESC,chunk_id LIMIT 1")->fetchColumn();
            self::assertNotSame($scope->tenantId->value.':'.$f->sets['allowed']->chunkSet->chunks[0]->ref->chunkId->value, $raw);
            $verified = [];
            foreach (['allowed','tie','other-snapshot'] as $name) {
                $s = $f->sets[$name]->chunkSet; $c = $s->chunks[0]; $r = $s->revisionRef;
                $verified[] = ['source_id'=>$r->sourceRef->sourceId->value,'source_version_id'=>$r->sourceRef->sourceVersionId->value,
                    'revision_id'=>$r->documentRevisionId->value,'chunk_set_id'=>$s->chunkSetId->value,'chunk_id'=>$c->ref->chunkId->value,
                    'chunk_digest'=>$c->ref->chunkDigest->value,'source_digest'=>$r->sourceRef->sourceDigest->value,
                    'ir_digest'=>$r->irDigest->value,'chunk_set_digest'=>$f->sets[$name]->manifestDigest->value,'literal_match'=>true];
            }
            $values = [$scope->tenantId->value,$scope->kbId->value,$scope->snapshotId->value,$scope->generationId->value,
                json_encode(array_column($verified,'source_version_id')),null,'[]','[]','gravity',false,json_encode($verified),1];
            $explain = json_decode($db->execute('EXPLAIN (ANALYZE,VERBOSE,FORMAT JSON) ' . SearchSql::select($db), $values)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            self::assertSame('Limit', $explain[0]['Plan']['Node Type']);
            self::assertSame(1,$explain[0]['Plan']['Actual Rows']);
            $walk = function(array $node) use (&$walk): ?array {
                if (($node['Subplan Name']??'')==='CTE permitted') { return $node; }
                foreach ($node['Plans']??[] as $child) { $found=$walk($child); if ($found!==null) { return $found; } }
                return null;
            };
            $permitted = $walk($explain[0]['Plan']); self::assertNotNull($permitted);
            self::assertSame(2,$permitted['Actual Rows']);
            self::assertStringNotContainsString('ts_rank', json_encode($permitted));
            self::assertStringNotContainsString('"Node Type":"Limit"', json_encode($permitted));
            if (getenv('LEXICAL_PLAN_EVIDENCE') !== false) {
                $portable = str_replace($db->schema, 'synthetic_schema', json_encode($explain,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                file_put_contents(getenv('LEXICAL_PLAN_EVIDENCE'),$portable."\n");
            }
        });
    }
    public function testExactIdentifiersPhrasesAndKbIdDoNotBroaden(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            foreach (['identifier','function','kb-id','phrase'] as $id) { $this->assertFixture($f,$id); }
            self::assertSame([], $f->names($f->index->search($f->scope(),new D\Query('DOC-2026/004'),10)));
            self::assertSame(['exact'], $f->names($f->index->search($f->scope(),new D\Query('"DOC-2026/0042 uses foo_bar.v2"'),10)));
        });
    }
    public function testCyrillicCjkMixedAndNoMatchHaveLiteralExpectedEvidence(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            foreach (['cyrillic','cjk','japanese','mixed','no-match','cjk-no-match','simple-not-morphology','punctuation','negative-only'] as $id) { $this->assertFixture($f,$id); }
            $list = $f->index->search($f->scope(),new D\Query('資料庫'),3);
            $candidate = $list->candidates[0]; $chunk = $f->sets['cjk']->chunkSet->chunks[0];
            self::assertEquals($chunk->ref,$candidate->ref);
            self::assertSame($f->data['cjk']['text'],$chunk->spans[0]->slice($chunk->text()));
            self::assertSame($f->data['cjk']['textDigest'],hash('sha256',$chunk->text()));
            self::assertSame(['ref','rank','score'],array_keys(get_object_vars($candidate)));
        });
    }
    public function testOriginalRevisionAndTypedFiltersRemainExact(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $text = 'Инструкция foo_bar.v2 ревизия 7';
            $v7 = $f->sets['revision-seven']->chunkSet->revisionRef->sourceRef->sourceVersionId;
            $v8 = $f->sets['revision-eight']->chunkSet->revisionRef->sourceRef->sourceVersionId;
            $q = new D\Query($text,[$v7]);
            $scope = $f->scope(['revision-seven','revision-eight'],1,$q);
            self::assertSame(['revision-seven'],$f->names($f->index->search($scope,$q,1)));
            self::assertSame($text,$q->text()); self::assertSame($text,(new LiteralQuery($text))->original);
            self::assertSame([$v7],$scope->allowedSourceVersionIds);
            self::assertSame([], $f->names($f->index->search($scope,new D\Query($text,[$v8]),1)));
            self::assertSame([], $f->names($f->index->search($scope,new D\Query($text,null,[]),1)));
            self::assertSame([], $f->names($f->index->search($f->scope(['revision-seven']),new D\Query($text,null,[new D\CollectionId(LexicalFixture::id(200))]),1)));
            $db = $f->catalog->db;
            foreach ([200=>1007,201=>1008] as $collection=>$source) {
                $db->execute('INSERT INTO '.$db->table('collections').' (tenant_id,id,kb_id,name,metadata) VALUES (?,?,?,?,?)',
                    [LexicalFixture::id(1),LexicalFixture::id($collection),LexicalFixture::id(100),'synthetic','{}']);
                $db->execute('INSERT INTO '.$db->table('source_memberships').' (tenant_id,id,source_id,kb_id,collection_id,metadata) VALUES (?,?,?,?,?,?)',
                    [LexicalFixture::id(1),$f->catalog->ids->next()->value,LexicalFixture::id($source),LexicalFixture::id(100),LexicalFixture::id($collection),'{}']);
            }
            self::assertSame(['revision-seven'],$f->names($f->index->search($f->scope(['revision-seven','revision-eight']),
                new D\Query('foo_bar.v2',null,[new D\CollectionId(LexicalFixture::id(200))]),1)));
            self::assertSame(str_repeat('a',64),$scope->hostScopeHash->value);
        });
    }
    public function testSqlLikeInputIsDataAndCannotChangeTheCorpus(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $before = $f->catalog->count('lexical_documents'); $this->assertFixture($f,'sql-like');
            foreach (["x' OR 1=1 --", '"; SELECT * FROM sources; --', 'tenant_id=2 OR true', '\\%_'] as $text) {
                $list = $f->index->search($f->scope(['allowed']),new D\Query($text),1);
                foreach ($list->candidates as $candidate) { self::assertEquals($f->sets['allowed']->chunkSet->chunks[0]->ref,$candidate->ref); }
            }
            self::assertSame($before,$f->catalog->count('lexical_documents'));
        });
    }
    public function testRogueCandidateOutsideManifestCannotCrowdTopK(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            self::assertSame(1,(int)$f->catalog->db->execute('SELECT count(*) FROM '.$f->catalog->db->table('lexical_documents').' WHERE tenant_id=? AND chunk_id=?',[LexicalFixture::id(1),LexicalFixture::id(6999)])->fetchColumn());
            $list = $f->index->search($f->scope(['allowed']),new D\Query('gravity'),1);
            self::assertSame(['allowed'],$f->names($list));
            self::assertNotSame(LexicalFixture::id(6999),$list->candidates[0]->ref->chunkId->value);
        },true,true);
    }
    public function testIndexReceiptReplayAndSealedWriteRejection(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $set = $f->sets['exact']; $receipt = $f->receipts['exact'];
            try {
                new PdoLexicalIndex(new \KnowKit\Adapter\Postgres\Catalog\Connection($f->catalog->db->pdo,$f->catalog->db->schema),
                    $f->catalog->tx,PortFixture::validator(),new CanonicalChunkSets(array_values($f->sets)),$f->catalog->ids);
                self::fail('Transaction connection mismatch accepted');
            } catch (\InvalidArgumentException $error) { self::assertSame('One bound catalog connection required',$error->getMessage()); }
            self::assertSame($set->manifestDigest,$receipt->manifestDigest); self::assertSame(1,$receipt->indexedCount);
            self::assertEquals($receipt,$f->indexSet($set->chunkSet));
            self::assertSame(count($f->sets),$f->catalog->count('lexical_documents'));
            LexicalFixture::failure(fn()=>$f->index->search($f->scope(),new D\Query('gravity'),1),'sealed_generation_unavailable',D\Outcome::Unavailable);
            $s = $set->chunkSet; $bad = new D\ChunkSet($s->revisionRef,$s->chunkSetId,$s->chunkerSignature,[]);
            LexicalFixture::failure(fn()=>$f->indexSet($bad),'immutable_conflict');
            $f->catalog->tx->run(function() use ($f,$s): void {
                $f->catalog->tx->lockTenant(new D\TenantId(LexicalFixture::id(2)),D\LockMode::Update);
                LexicalFixture::failure(fn()=>$f->index->index(new D\GenerationId(LexicalFixture::id(14)),$s),'scope_mismatch',D\Outcome::Denied);
            });
            $f->seal(); LexicalFixture::failure(fn()=>$f->indexSet($set->chunkSet),'generation_sealed');
            $f->catalog->db->execute('UPDATE '.$f->catalog->db->table('knowledge_bases').' SET active_snapshot_id=NULL');
            self::assertSame(['exact'],$f->names($f->index->search($f->scope(),new D\Query('KB-2026-001'),1)));
            self::assertSame($s->chunks[0]->ref,$f->index->search($f->scope(),new D\Query('KB-2026-001'),1)->candidates[0]->ref);
        },false);
    }
    public function testCanonicalAndManifestTamperingFailClosed(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $set = $f->sets['exact']; $m = $set->manifest(); $entry = $m->get('chunks')[0];
            $bad = new D\ChunkingResult($set->chunkSet,$m->with('chunks',[$entry->with('chunkId',LexicalFixture::id(6999))]));
            LexicalFixture::failure(fn()=>new CanonicalChunkSets([$bad]),'manifest_mismatch');
            $wire = $f->manifests[1]->toWire(); $members = $wire->get('members');
            $members[0] = $members[0]->with('chunkSetDigest',str_repeat('f',64));
            // Tamper a building generation, then seal a schema-valid but dishonest manifest.
            $other = $wire->with('generationId',LexicalFixture::id(15))->with('members',$members);
            $db = $f->catalog->db;
            $db->execute('INSERT INTO '.$db->table('index_generations')." (tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,?,'building')",[LexicalFixture::id(1),LexicalFixture::id(15),LexicalFixture::id(100),PdoLexicalIndex::PROFILE]);
            // Existing snapshot is sealed: use another exact sealed snapshot with only a generation mapping.
            $db->execute('INSERT INTO '.$db->table('publication_snapshots').' VALUES (?,?,?,?,?,false)',[LexicalFixture::id(1),LexicalFixture::id(111),LexicalFixture::id(100),str_repeat('b',64),'2026-11-07T00:00:00Z']);
            $db->execute('INSERT INTO '.$db->table('snapshot_generations').' VALUES (?,?,?,?,?)',[LexicalFixture::id(1),LexicalFixture::id(111),LexicalFixture::id(100),LexicalFixture::id(15),PdoLexicalIndex::PROFILE]);
            $db->execute('UPDATE '.$db->table('index_generations')." SET state='sealed',manifest_json=?,manifest_digest=? WHERE tenant_id=? AND id=?",[\KnowKit\Domain\Contract\Jcs::canonicalize($other),HashDomain::IndexManifest->digest($other),LexicalFixture::id(1),LexicalFixture::id(15)]);
            $db->execute('UPDATE '.$db->table('publication_snapshots').' SET sealed=true WHERE tenant_id=? AND id=?',[LexicalFixture::id(1),LexicalFixture::id(111)]);
            $base = PortFixture::scope();
            // Materialize from the same trusted bindings, using the fixture's own grant format.
            $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[],'allowedSourceVersionIds'=>[LexicalFixture::id(2001)],'grantEpoch'=>'42','policyBindingDigest'=>str_repeat('c',64),'expiresAt'=>'2026-11-07T00:00:30Z']),PortFixture::validator());
            $scope = D\AuthorizedSearchScope::materialize($base,$grant,new D\SnapshotRef($base->tenantId,$base->kbId,new D\SnapshotId(LexicalFixture::id(111)),new D\Fingerprint(str_repeat('b',64))),new D\GenerationId(LexicalFixture::id(15)),[new D\SourceVersionId(LexicalFixture::id(2001))],new D\UtcInstant('2026-11-07T00:00:01Z'));
            LexicalFixture::failure(fn()=>$f->index->search($scope,new D\Query('gravity'),1),'manifest_mismatch');
        });
    }
    public function testBackendFailureIsUnavailableAndNeverEmpty(): void
    {
        LexicalFixture::run(function(LexicalFixture $f): void {
            $db=$f->catalog->db; $role=$db->pdo->query('SELECT current_user')->fetchColumn();
            $db->pdo->exec('RESET ROLE');
            $db->pdo->exec('REVOKE SELECT ON '.$db->table('lexical_documents').' FROM "'.$role.'"');
            $db->pdo->exec('SET ROLE "'.$role.'"');
            LexicalFixture::failure(fn()=>$f->index->search($f->scope(),new D\Query('absentterm'),1),'storage_unavailable',D\Outcome::Unavailable);
        });
    }
    public function testUnsupportedPrefilterProfileRejectsBeforeFactoryAndTransmission(): void
    {
        $id = new D\AdapterId('test-vector-without-filter'); $calls = new class { public int $factory=0; public int $transmissions=0; };
        $registry = new AdapterRegistry();
        $registry->register(new AdapterDescriptor($id,D\AdapterKind::Vector,'1',new JsonObject(['$id'=>'urn:test:unsupported-vector','type'=>'object','unevaluatedProperties'=>false]),[]),
            new class($calls) implements Factory {
                public function __construct(private object $calls) {}
                public function create(D\EffectiveProfile $profile): object { $this->calls->factory++; $this->calls->transmissions++; throw new \LogicException('Unfiltered backend invoked'); }
            });
        $registry->boot();
        $profile = new D\EffectiveProfile('filter-required','1',['vector'=>$id],[],[$id->value=>[D\Capability::VectorPrefilter]]);
        LexicalFixture::failure(fn()=>$registry->resolve($id,$profile),'unsupported_capability');
        self::assertSame(0,$calls->factory); self::assertSame(0,$calls->transmissions);
    }
    private function assertFixture(LexicalFixture $f, string $id): void
    {
        foreach (LexicalFixture::corpus()['queries'] as $case) {
            if ($case['id']!==$id) { continue; }
            $list = $f->index->search($f->scope(),new D\Query($case['query']),20);
            self::assertSame($case['expected'],$f->names($list),$id);
            foreach ($list->candidates as $n=>$candidate) { self::assertSame($n+1,$candidate->rank); self::assertGreaterThan(0,$candidate->score); }
            return;
        }
        self::fail('Missing authored query expectation');
    }
}
