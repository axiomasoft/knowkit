<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Application\Jobs\VerifiedCheckpoints;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\ReadableStream;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\{CatalogFixture, TestIds};

require_once dirname(__DIR__,2).'/storage/catalog/bootstrap.php';

final class Bytes implements ReadableStream
{
    private int $position=0;
    public function __construct(private string $bytes) {}
    public function read(int $maxBytes): string { $s=substr($this->bytes,$this->position,$maxBytes); $this->position+=strlen($s); return $s; }
    public function eof(): bool { return $this->position>=strlen($this->bytes); }
    public function close(): void {}
}

final class Fixture
{
    public readonly PdoJobQueue $queue;
    public readonly D\Scope $scope;
    public readonly D\ProcessingIdentity $processing;
    public function __construct(public readonly CatalogFixture $catalog, public readonly string $directory)
    {
        $catalog->tenant(); $catalog->kb(); $catalog->source();
        $this->scope=$catalog->scope();
        $this->resource(300,'source');
        $catalog->db->execute('INSERT INTO source_versions(tenant_id,id,source_id,blob_resource_id,source_digest,byte_length,media_type,rights_ref,idempotency_fingerprint,created_at)
            VALUES (?,?,?,?,?,6,\'text/plain\',\'synthetic:test\',?,clock_timestamp())',
            [self::id(1),self::id(3),self::id(2),self::id(300),hash('sha256','source'),str_repeat('b',64)]);
        $catalog->db->execute('INSERT INTO source_version_state VALUES (?,?,\'usable\',NULL,NULL)',[self::id(1),self::id(3)]);
        $base=PortFixture::fixture()->get('base')->with('sourceVersionId',self::id(3))->with('sourceDigest',hash('sha256','source'));
        $this->processing=PortFixture::processing($base);
        $this->queue=self::makeQueue($catalog->db,$this->scope,$directory);
    }
    public static function id(int $n): string { return CatalogFixture::id($n); }
    public static function run(callable $test): void
    {
        $dir=__DIR__.'/.phpunit.cache/run-'.bin2hex(random_bytes(8)); mkdir($dir,0700,true);
        try { CatalogFixture::run(fn($catalog)=>$test(new self($catalog,$dir))); }
        finally { foreach (glob($dir.'/*')?:[] as $file) { unlink($file); } rmdir($dir); }
    }
    public static function makeQueue(Connection $db,D\Scope $scope,string $directory,?PdoCatalogTransaction $tx=null): PdoJobQueue
    {
        $verifier=new VerifiedCheckpoints(
            static function(D\Sha256 $digest) use($db,$directory): string {
                if ($db->pdo->inTransaction()) { throw new \LogicException('Manifest I/O inside transaction'); }
                return file_get_contents($directory.'/manifest-'.$digest->value) ?: throw new \RuntimeException('Missing manifest');
            },
            static function(D\TenantId $tenant,D\ResourceId $resource) use($db,$scope,$directory): ReadableStream {
                $scope->assertTenant($tenant);
                if ($db->pdo->inTransaction()) { throw new \LogicException('Resource I/O inside transaction'); }
                $bytes=file_get_contents($directory.'/resource-'.$resource->value);
                return new Bytes($bytes===false?throw new \RuntimeException('Missing resource'):$bytes);
            });
        return new PdoJobQueue($db,$tx??new PdoCatalogTransaction($db),$scope,new TestIds(random_int(30000000,90000000)),
            ['extract'=>['extract'],'ingest'=>['extract','chunk','index']],$verifier->load(...));
    }
    public static function scopeValue(JsonObject $w): D\Scope
    {
        return new D\Scope(new D\TenantId($w->get('tenantId')),new D\KbId($w->get('kbId')),new D\ActorId($w->get('actorId')),
            $w->get('purpose'),new D\Decimal($w->get('policyEpoch')),new D\Sha256($w->get('hostScopeHash')));
    }
    public function command(string $type='extract',?D\ProcessingIdentity $processing=null,?D\GenerationId $generation=null): D\JobCommand
    {
        return new D\JobCommand($this->scope,new D\SourceVersionRef($this->scope->tenantId,new D\SourceId(self::id(2)),new D\SourceVersionId(self::id(3)),new D\SourceDigest(hash('sha256','source'))),
            $generation,new D\JobType($type),$processing??$this->processing,new D\UtcInstant('2026-01-01T00:00:00Z'));
    }
    public function enqueue(string $key='job',string $type='extract',?D\GenerationId $generation=null): D\JobId
    { return $this->queue->enqueue($this->command($type,null,$generation),new D\IdempotencyKey($key)); }
    public function lease(string $type='extract',int $worker=900): D\Lease
    { return $this->queue->lease(new D\JobType($type),new D\WorkerId(self::id($worker)))??throw new \RuntimeException('Expected lease'); }
    public function row(?D\JobId $id=null): array
    { return $this->catalog->db->row('SELECT * FROM jobs WHERE tenant_id=?'.($id===null?' ORDER BY id LIMIT 1':' AND id=?'),$id===null?[self::id(1)]:[self::id(1),$id->value])??throw new \RuntimeException('Expected job'); }
    public function expire(D\Lease $lease,int $seconds=100): void
    { $this->catalog->db->execute('UPDATE jobs SET lease_until=clock_timestamp()-make_interval(secs=>?) WHERE tenant_id=? AND id=?',[$seconds,self::id(1),$lease->jobId->value]); }
    public function resource(int $id,string $bytes,int $source=2): JsonObject
    {
        self::durableFile($this->directory.'/resource-'.self::id($id),$bytes);
        $this->catalog->db->execute('INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
            VALUES (?,?,?,\'owned\',\'fixture\',?,?,?, ?,\'accepted\')',
            [self::id(1),self::id($id),self::id($source),'fixture-'.$id,'version-1',hash('sha256',$bytes),(string)strlen($bytes)]);
        return new JsonObject(['resourceId'=>self::id($id),'digest'=>hash('sha256',$bytes),'byteLength'=>(string)strlen($bytes)]);
    }
    public static function durableFile(string $path,string $bytes): void
    { $h=fopen($path,'wb'); try { fwrite($h,$bytes); fflush($h); fsync($h); } finally { fclose($h); } $d=fopen(dirname($path),'r'); try { fsync($d); } finally { fclose($d); } }
    public function manifest(D\Lease $lease,int $resource=310,bool $uncertain=false,array $changes=[]): D\JobCheckpointManifest
    {
        $job=$this->row($lease->jobId); $refs=[];
        if (!$uncertain) {
            $r=$this->catalog->db->row('SELECT digest,byte_length FROM resource_ledger WHERE tenant_id=? AND id=?',[self::id(1),self::id($resource)]);
            $refs[]=$r===null?$this->resource($resource,'artifact-'.$resource):new JsonObject(['resourceId'=>self::id($resource),'digest'=>$r['digest'],'byteLength'=>(string)$r['byte_length']]);
        }
        $wire=new JsonObject(array_replace(['schemaVersion'=>'job-checkpoint-1.0','tenantId'=>self::id(1),'kbId'=>self::id(100),'sourceId'=>self::id(2),
            'sourceVersionId'=>self::id(3),'jobId'=>$lease->jobId->value,'sourceEpoch'=>$lease->sourceEpoch->value,
            'processingDigest'=>$job['processing_digest'],'stage'=>$job['stage'],'inputDigest'=>$job['stage_input_digest'],
            'outputDigest'=>HashDomain::ResourceInventory->digest($refs),'uncertainExternalEffect'=>$uncertain,'resources'=>$refs],$changes));
        $m=new D\JobCheckpointManifest($wire); self::durableFile($this->directory.'/manifest-'.$m->digest()->value,Jcs::canonicalize($wire)); return $m;
    }
    public function finishStages(D\Lease $lease): void
    {
        while ((int)$this->row($lease->jobId)['completed_stage_count']<count(json_decode($this->row($lease->jobId)['stage_plan_json'],true))) {
            $m=$this->manifest($lease,310+(int)$this->row($lease->jobId)['completed_stage_count']);
            if (!$this->queue->checkpoint($lease,$m->checkpoint())) { throw new \RuntimeException('Stage checkpoint refused'); }
        }
    }
    public function result(D\Outcome $outcome=D\Outcome::Success): D\JobResult { return new D\JobResult($outcome,new D\Sha256(hash('sha256','result'))); }
    public function connection(): Connection
    {
        $pdo=new \PDO(getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null);
        $role=$this->catalog->db->pdo->query('SELECT current_user')->fetchColumn(); Connection::schema($role);
        $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$this->catalog->db->schema);
        return new Connection($pdo,$this->catalog->db->schema);
    }
}
