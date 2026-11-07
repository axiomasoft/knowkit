<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Adapter\Postgres\Dependencies\{NodeMetadata, PdoDependencyStore};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__,2).'/storage/catalog/bootstrap.php';

/** Synthetic immutable metadata, no claim of real parser/provider/blob qualification. */
final class Fixture
{
    public readonly D\TenantId $tenant;
    public readonly PdoDependencyStore $graph;
    public function __construct(public readonly CatalogFixture $catalog)
    {
        $this->tenant=new D\TenantId(self::id(1));
        $this->graph=new PdoDependencyStore($catalog->db,$catalog->tx,$this->tenant,$catalog->ids);
        $catalog->tenant(); $catalog->kb(); $catalog->source(); $this->version(3);
    }
    public static function id(int $n): string { return CatalogFixture::id($n); }
    public static function run(callable $test): void { CatalogFixture::run(fn($f)=>$test(new self($f))); }
    public function atomic(callable $operation,D\LockMode $mode=D\LockMode::Update): mixed
    {
        return $this->catalog->tx->run(function() use($operation,$mode) {
            $this->catalog->db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->catalog->db->execute("SET LOCAL lock_timeout='500ms'");
            $this->catalog->db->execute("SET LOCAL statement_timeout='2s'");
            $this->catalog->tx->lockTenant($this->tenant,$mode); return $operation();
        });
    }
    public function version(int $id,int $source=2): void
    {
        $this->catalog->resource(3000+$id,$source,1,'owned',hash('sha256','version-'.$id));
        $this->catalog->db->execute('INSERT INTO source_versions VALUES (?,?,?,?,?,15,\'text/plain\',\'synthetic\',?,\'2026-11-06T12:00:00Z\')',
            [self::id(1),self::id($id),self::id($source),self::id(3000+$id),hash('sha256','version-'.$id),hash('sha256','version-request-'.$id)]);
        $this->catalog->db->execute("INSERT INTO source_version_state VALUES (?,?,'usable',NULL,NULL)",[self::id(1),self::id($id)]);
    }
    public function ref(string $kind,int $id,int $tenant=1): D\ResourceRef
    {
        $type=match($kind) {'source'=>D\SourceId::class,'source_version'=>D\SourceVersionId::class,
            'document_revision'=>D\DocumentRevisionId::class,'resource'=>D\ResourceId::class,'chunk'=>D\ChunkId::class};
        return new D\ResourceRef(new D\TenantId(self::id($tenant)),$kind,new $type(self::id($id)));
    }
    public function node(string $kind,int $id): D\Node
    {
        $ref=$this->ref($kind,$id); $m=(new NodeMetadata($this->catalog->db,$this->catalog->tx))->resolve($ref);
        return new D\Node($ref,new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($m['identity'])));
    }
    public function register(string $kind,int $id,array $parents=[]): D\Node
    {
        $node=$this->node($kind,$id); $this->graph->register($node,new D\Parents($this->tenant,$parents)); return $node;
    }
    public function derived(int $id,array $parents,int $source=2): D\Node
    {
        $this->catalog->resource($id,$source,1,'owned',hash('sha256','derived-'.$id));
        return $this->register('resource',$id,$parents);
    }
    public function decision(D\ResourceRef ...$refs): D\Decision
    { return $this->graph->checkAncestors(new D\NodeRefs($this->tenant,$refs))->decision; }
    public function event(string $type='source.revoked',int $source=2,int $version=3,string $epoch='1'): D\LifecycleEvent
    {
        $payload=match($type) {
            'source_version.corrected'=>new JsonObject(['sourceVersionId'=>self::id($version),'usability'=>'corrected','correctedByVersionId'=>null]),
            'source.deletion_requested'=>new JsonObject(['sourceId'=>self::id($source),'lifecycleState'=>'deletion_requested','lifecycleEpoch'=>$epoch]),
            'retention_hold.changed'=>new JsonObject(['sourceId'=>self::id($source),'retentionHold'=>true]),
            default=>new JsonObject(['sourceId'=>self::id($source),'reason'=>'source_revoked','lifecycleEpoch'=>$epoch,'appliesTo'=>'future_versions']),
        };
        return new D\LifecycleEvent($this->catalog->events->create($this->tenant,'source',new D\SourceId(self::id($source)),new D\Decimal($epoch),$type,$payload));
    }
    public function revoke(D\LifecycleEvent $event): void
    {
        $this->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=1 WHERE tenant_id=? AND id=?",[self::id(1),self::id(2)]);
        $this->graph->invalidate($event);
    }
    public function chunks(array $extraParents=[]): void
    {
        $db=$this->catalog->db; $t=self::id(1); $this->catalog->resource(301,2,1,'owned',str_repeat('b',64)); $this->catalog->resource(302,2,1,'owned',str_repeat('d',64));
        $db->execute("INSERT INTO document_revisions VALUES (?,?,?,'test','1',?,'ir-1.0',?,?,'unknown',?, '2026-11-06T12:00:00Z')",
            [$t,self::id(4),self::id(3),str_repeat('a',64),str_repeat('b',64),self::id(301),'{"state":"unknown","counts":{"processed":0,"empty":0,"failed":0,"skipped":0},"missingFeatures":[],"diagnostics":[]}']);
        $db->execute('INSERT INTO documents VALUES (?,?,?,\'document\')',[$t,self::id(5),self::id(4)]);
        $db->execute('INSERT INTO chunk_sets VALUES (?,?,?,?,?)',[$t,self::id(10),self::id(4),'{"name":"test","version":"1","profileDigest":"'.str_repeat('a',64).'"}',str_repeat('e',64)]);
        $db->execute("INSERT INTO segments VALUES (?,?,?,'segment-1',?,?,'ok',0)",[$t,self::id(7),self::id(5),self::id(302),str_repeat('d',64)]);
        $db->execute("INSERT INTO blocks VALUES (?,?,?,?,'block-1',NULL,'paragraph','source','{\"kind\":\"verbatim\"}',0,'[]','{}')",[$t,self::id(8),self::id(4),self::id(5)]);
        $spans='[{"kind":"segment","segmentId":"segment-1","blockId":"block-1","byteStart":0,"byteEnd":4,"textDigest":"'.str_repeat('d',64).'"}]';
        $db->execute("INSERT INTO chunks VALUES (?,?,?,?,?,?,'{}',15,'[]',?)",[$t,self::id(11),self::id(10),str_repeat('d',64),self::id(5),$spans,self::id(302)]);
        $this->atomic(function() use($extraParents) {
            $root=$this->register('source_version',3); $revision=$this->register('document_revision',4,[$root->ref,...$extraParents]); $this->register('chunk',11,[$revision->ref]);
        });
        // Structural selection/usage seed only; this task does not issue disclosure grants.
        $db->execute("INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,'postgres-fts-simple-v1','building')",[$t,self::id(14),self::id(100)]);
        $db->execute("INSERT INTO publication_snapshots VALUES (?,?,?,?, '2026-11-06T12:00:00Z',false)",[$t,self::id(110),self::id(100),str_repeat('a',64)]);
        $db->execute("INSERT INTO selections VALUES (?,?,?,?,'{}',?,NULL,?,?,?,'{}','postgres-fts-simple-v1',NULL,'[{}]','2026-11-06T12:05:00Z')",
            [$t,self::id(15),str_repeat('a',64),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),self::id(110),self::id(14)]);
    }
    public function usage(int $id=16,?string $run=null,bool $prepare=true): D\Usage
    {
        $chunk=$this->atomic(fn()=>(new NodeMetadata($this->catalog->db,$this->catalog->tx))->resolve($this->ref('chunk',11))['identity']);
        $wire=new JsonObject(['usageId'=>self::id($id),'tenantId'=>self::id(1),'disclosureAttemptId'=>self::id(1000+$id),'selectionId'=>self::id(15),
            'actorId'=>self::id(101),'purpose'=>'catalog.test','hostScopeHash'=>str_repeat('a',64),'runRef'=>$run,
            'fragmentRefs'=>[new JsonObject(['chunkRef'=>$chunk,'fragmentDigest'=>hash('sha256','fragment')])],
            'checkedAt'=>'2026-11-06T12:00:00Z','policyBindingDigest'=>str_repeat('b',64),'status'=>'prepared_disclosure']);
        if ($prepare) {
            $this->catalog->db->execute("INSERT INTO evidence_usages VALUES (?,?,?,?,?,'catalog.test',?,?,?,?,'2026-11-06T12:00:00Z',NULL,?,NULL)",
                [self::id(1),self::id($id),self::id(1000+$id),self::id(100),self::id(101),$run,self::id(15),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64)]);
        }
        return new D\Usage($wire,PortFixture::validator());
    }
    public static function failure(callable $operation,string $code,D\Outcome $outcome=D\Outcome::Incompatible): void
    {
        try { $operation(); Assert::fail('Forbidden operation succeeded'); }
        catch (D\DomainFailure $e) { Assert::assertSame($code,$e->errorCode); Assert::assertSame($outcome,$e->outcome); }
    }
}
