<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Adapter\Postgres\Catalog\{Connection, Migrate, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Application\Jobs\VerifiedCheckpoints;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\ReadableStream;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\{CatalogFixture, TestIds};
use KnowKit\Tests\Tooling\DatabaseSandbox;

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
        try { MigratedSchema::run(fn($catalog)=>$test(new self($catalog,$dir))); }
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

/** One migrated schema per process. Each case truncates rows; fences and outbox state are not reused. */
final class MigratedSchema
{
    private static ?DatabaseSandbox $sandbox=null;
    private static string $role='';
    /** @var list<string> */
    private static array $tables=[];
    /** @var array<string, true> */
    private static array $relations=[];
    /** @var array<string, true> */
    private static array $triggers=[];
    /** @var array<string, true> */
    private static array $functions=[];
    private static int $depth=0;

    public static function run(callable $test): mixed
    {
        if (self::$depth>0) { return CatalogFixture::run($test); }
        self::boot();
        self::$depth++;
        try {
            self::reset();
            return $test(new CatalogFixture(self::$sandbox));
        } finally {
            self::$depth--;
            self::restoreRole();
        }
    }

    private static function boot(): void
    {
        if (self::$sandbox!==null) { return; }
        $sandbox=new DatabaseSandbox(); $pdo=$sandbox->connection; $role='';
        try {
            $pdo->exec('CREATE SCHEMA '.$sandbox->schema);
            $pdo->exec('SET search_path TO '.$sandbox->schema);
            (new Migrate($pdo,$sandbox->schema))->apply(CatalogFixture::root().'/packages/core/migrations/postgres/001_metadata.sql');
            $role='knowkit_test_'.bin2hex(random_bytes(8));
            $pdo->exec('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER');
            $pdo->exec('GRANT USAGE ON SCHEMA '.$sandbox->schema.' TO '.$role);
            $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA '.$sandbox->schema.' TO '.$role);
            self::$sandbox=$sandbox; self::$role=$role; self::snapshot();
            register_shutdown_function(static function() use($sandbox,$role): void {
                self::discard($sandbox->connection,$sandbox->schema,$role);
            });
            $pdo->exec('SET ROLE '.$role);
        } catch (\Throwable $error) {
            self::discard($pdo,$sandbox->schema,$role);
            self::$sandbox=null; self::$role='';
            throw $error;
        }
    }

    private static function discard(\PDO $pdo,string $schema,string $role): void
    {
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (\Throwable) {}
        try { $pdo->exec('RESET ROLE'); } catch (\Throwable) {}
        if ($role!=='') {
            try { $pdo->exec('DROP OWNED BY '.$role); } catch (\Throwable) {}
            try { $pdo->exec('DROP ROLE IF EXISTS '.$role); } catch (\Throwable) {}
        }
        try { $pdo->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); } catch (\Throwable) {}
    }

    private static function snapshot(): void
    {
        $pdo=self::$sandbox->connection; $schema=self::$sandbox->schema; $quoted=$pdo->quote($schema);
        self::$tables=[]; self::$relations=[]; self::$triggers=[]; self::$functions=[];
        foreach ($pdo->query("SELECT relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND c.relkind IN ('r','v','m')") as $row) {
            self::$relations[$row['relname']]=true;
            if ($row['relname']!=='schema_migrations') { self::$tables[]=$row['relname']; }
        }
        foreach ($pdo->query("SELECT c.relname,t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND NOT t.tgisinternal") as $row) {
            self::$triggers[$row['relname']."\0".$row['tgname']]=true;
        }
        foreach ($pdo->query("SELECT p.oid::regprocedure AS sig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=$quoted") as $row) {
            self::$functions[$row['sig']]=true;
        }
    }

    private static function reset(): void
    {
        $pdo=self::$sandbox->connection;
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->exec('RESET ROLE');
        $schema=self::$sandbox->schema; $pdo->exec('SET search_path TO '.$schema); $quoted=$pdo->quote($schema);
        $counts=$pdo->query("SELECT
            (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND NOT t.tgisinternal AND t.tgenabled='D') AS disabled,
            (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND NOT t.tgisinternal) AS triggers,
            (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=$quoted) AS functions,
            (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND c.relkind IN ('r','v','m')) AS relations")->fetch(\PDO::FETCH_ASSOC);
        if ((int)$counts['triggers']<count(self::$triggers) || (int)$counts['functions']<count(self::$functions) || (int)$counts['relations']<count(self::$relations)) {
            throw new \RuntimeException('Migrated schema objects were removed; lease and outbox fences cannot be reused');
        }
        if ((int)$counts['disabled']!==0 || (int)$counts['triggers']!==count(self::$triggers) || (int)$counts['functions']!==count(self::$functions) || (int)$counts['relations']!==count(self::$relations)) {
            self::repair($pdo,$schema,$quoted);
        }
        if (self::$tables!==[]) {
            $list=implode(',',array_map(static fn(string $table): string => self::ident($schema).'.'.self::ident($table),self::$tables));
            // Fail closed if a leaked backend still holds a fence lock; this does not shorten product lock_timeout.
            $pdo->exec("SET lock_timeout='2s'");
            $pdo->exec('TRUNCATE '.$list.' RESTART IDENTITY CASCADE');
        }
        $pdo->exec('DISCARD TEMP');
        $pdo->exec('RESET ALL');
        $pdo->exec('SET search_path TO '.$schema);
        $pdo->exec('SET ROLE '.self::$role);
    }

    private static function repair(\PDO $pdo,string $schema,string $quoted): void
    {
        foreach ($pdo->query("SELECT DISTINCT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND NOT t.tgisinternal AND t.tgenabled='D'") as $row) {
            $pdo->exec('ALTER TABLE '.self::ident($schema).'.'.self::ident($row['relname']).' ENABLE TRIGGER ALL');
        }
        foreach ($pdo->query("SELECT c.relname,t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND NOT t.tgisinternal") as $row) {
            if (!isset(self::$triggers[$row['relname']."\0".$row['tgname']])) {
                $pdo->exec('DROP TRIGGER '.self::ident($row['tgname']).' ON '.self::ident($schema).'.'.self::ident($row['relname']));
            }
        }
        foreach ($pdo->query("SELECT p.oid::regprocedure AS sig FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=$quoted") as $row) {
            if (!isset(self::$functions[$row['sig']])) { $pdo->exec('DROP FUNCTION '.$row['sig']); }
        }
        foreach ($pdo->query("SELECT relname,relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=$quoted AND c.relkind IN ('r','v','m')") as $row) {
            if (isset(self::$relations[$row['relname']])) { continue; }
            $kind=$row['relkind']==='v'?'VIEW':($row['relkind']==='m'?'MATERIALIZED VIEW':'TABLE');
            $pdo->exec('DROP '.$kind.' '.self::ident($schema).'.'.self::ident($row['relname']).' CASCADE');
        }
    }

    private static function restoreRole(): void
    {
        if (self::$sandbox===null) { return; }
        $pdo=self::$sandbox->connection;
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (\Throwable) {}
        try {
            $pdo->exec('RESET ROLE');
            $pdo->exec('SET search_path TO '.self::$sandbox->schema);
            $pdo->exec('SET ROLE '.self::$role);
        } catch (\Throwable) {}
    }

    private static function ident(string $name): string { return '"'.str_replace('"','""',$name).'"'; }
}
