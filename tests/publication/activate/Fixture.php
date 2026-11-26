<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Activate;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction, ChunkMetadata, ChunkSetMetadata};
use KnowKit\Adapter\Postgres\Documents\{AssetStorage, DocumentStorage, PdoDocumentStore, UnitStorage};
use KnowKit\Adapter\Postgres\IndexGenerations\{PdoReadBackend, PdoStagingGenerations, ResourceInventory};
use KnowKit\Adapter\Postgres\Publication\PdoActivation;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\{Builder, ProjectionReadiness};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Jobs\Leases\Bytes;
use KnowKit\Tests\Publication\Manifest\Fixture as Manifest;
use KnowKit\Tests\Storage\Catalog\{CurrentOwner, TestClock, TestIds};
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__).'/manifest/Fixture.php';

final class Services
{
    public PdoStagingGenerations $staging;
    public PdoActivation $store;
    public Publisher $publisher;
    public int $reads = 0;
    public MutationAuthorizer $authority;
    public function lifecycle(D\Scope $scope, ?P\Outbox $outbox = null, ?\Closure $afterInvalidate = null): \KnowKit\Application\Lifecycle\LifecycleCommands
    {
        $clock = new TestClock(); $ids = new TestIds(random_int(10000000,90000000));
        $events = new DomainEventFactory($clock,$ids,PortFixture::validator()); $outbox ??= new PdoOutbox($this->db,$this->tx);
        $repo = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogRepository($this->db,$this->tx,$this->authority->requireAllow(...),$clock,$ids,
            $events->create(...),$outbox,str_repeat('test cursor authentication ',2));
        $graph = new \KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore($this->db,$this->tx,$scope->tenantId,$ids);
        $dependencies = $afterInvalidate === null ? $graph : new class($graph,$afterInvalidate) implements P\DependencyStore {
            public function __construct(private P\DependencyStore $inner, private \Closure $after) {}
            public function register(D\Node $node,D\Parents $parents): void { $this->inner->register($node,$parents); }
            public function checkAncestors(D\NodeRefs $refs): D\UsabilityReport { return $this->inner->checkAncestors($refs); }
            public function recordUsage(D\Usage $usage): void { $this->inner->recordUsage($usage); }
            public function invalidate(D\LifecycleEvent $event): void { $this->inner->invalidate($event); ($this->after)(); }
        };
        $store = new \KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore($this->db,$this->tx,$scope->tenantId,$clock,$ids,
            PortFixture::validator(),$events->create(...),$dependencies,$outbox,$this->authority->requireAllow(...));
        return new \KnowKit\Application\Lifecycle\LifecycleCommands($store,$repo,$this->tx,$this->authority,$repo->acceptSourceVersion(...));
    }
    public function __construct(public Connection $db, public PdoCatalogTransaction $tx, D\ActivationRequest $r,
        string $directory, ?P\Outbox $outbox = null, ?P\AccessPolicy $owner = null, ?\Closure $beforeAccept = null, int $idStart = 500000)
    {
        $clock = new TestClock(); $ids = new TestIds($idStart); $events = new DomainEventFactory($clock,$ids,PortFixture::validator());
        $this->authority = new MutationAuthorizer($owner ?? new CurrentOwner($db),$clock);
        $outbox ??= new PdoOutbox($db,$tx);
        $backend = new PdoReadBackend($db,new CanonicalChunkSets($r->candidate->sets));
        $probe = new IndexReadabilityProbe($backend, function(D\TenantId $tenant, JsonObject $ref) use ($r,$directory): P\ReadableStream {
            $r->lease->scope->assertTenant($tenant);
            if ($this->db->pdo->inTransaction()) { throw new \LogicException('External object read under SQL guards'); }
            ++$this->reads; $path = $directory.'/resource-'.$ref->get('resourceId');
            if (!is_file($path)) { throw new D\DomainFailure(D\Outcome::Unavailable,'resource_missing'); }
            return new Bytes(file_get_contents($path));
        });
        $this->staging = new PdoStagingGenerations($db,$tx,$this->authority->prepare(...),$this->authority->requireAllow(...),
            new ResourceInventory($db),$probe->verify(...),$probe->verifyBackend(...),(new ProjectionReadiness())->check(...),$events->create(...),$outbox);
        $this->store = new PdoActivation($db,$tx,$this->staging,$clock,$ids,PortFixture::validator(),$events->create(...),$outbox);
        $store = $beforeAccept === null ? $this->store : new class($this->store,$beforeAccept) implements P\SnapshotActivation {
            public function __construct(private P\SnapshotActivation $inner, private \Closure $before) {}
            public function replay(D\ActivationRequest $r): ?D\PublishReceipt { return $this->inner->replay($r); }
            public function activate(D\ActivationRequest $r): D\PublishReceipt { ($this->before)(); return $this->inner->activate($r); }
        };
        $this->publisher = new Publisher($store,$this->authority,$probe);
    }
}

final class Fixture
{
    public function __construct(public Manifest $manifest) {}
    public static function run(callable $run): void { Manifest::run(fn(Manifest $m) => $run(new self($m))); }
    public static function id(int $n): string { return Manifest::id($n); }
    public static function data(): JsonObject
    { static $data=null; return $data ??= StrictJson::decode((string)file_get_contents(__DIR__.'/fixtures/v1/cases.json')); }
    public function db(): Connection { return $this->manifest->jobs->catalog->db; }
    /** Disposable schema owner's fault injection models backend data loss, then restores the app role. */
    public function loseProjection(): void
    {
        $db = $this->db(); $role = $db->execute('SELECT current_user')->fetchColumn(); Connection::schema($role);
        $db->pdo->exec('RESET ROLE');
        try {
            $db->pdo->exec('ALTER TABLE lexical_documents DISABLE TRIGGER lexical_seal');
            try { $db->execute('DELETE FROM lexical_documents WHERE tenant_id=? AND generation_id=?',[self::id(1),self::id(501)]); }
            finally { $db->pdo->exec('ALTER TABLE lexical_documents ENABLE TRIGGER lexical_seal'); }
        } finally { $db->pdo->exec('SET ROLE '.$role); }
    }
    public function services(D\ActivationRequest $r, ?P\Outbox $outbox = null, ?P\AccessPolicy $owner = null, ?\Closure $before = null, int $start = 0): Services
    { return new Services($this->db(),$this->manifest->jobs->catalog->tx,$r,$this->manifest->jobs->directory,$outbox,$owner,$before,$start ?: random_int(10000000,90000000)); }
    public function request(int $generation = 501, string $pointer = '0', string $key = 'publish', ?D\ChunkingResult $set = null, ?D\Lease $lease = null): D\ActivationRequest
    {
        $m = $this->manifest; $set ??= $m->set; $lease ??= $m->lease; $s = $lease->scope;
        $inventory = new ResourceInventory($this->db());
        $candidate = (new Builder(PortFixture::validator()))->build($s,new D\GenerationId(self::id($generation)),[$set],
            $inventory->resources($s->tenantId,[$set]),$inventory->coverage($s->tenantId,[$set]));
        return new D\ActivationRequest($lease,$candidate,new D\Decimal($pointer),new D\IdempotencyKey($key));
    }
    public function seal(D\ActivationRequest $r): void
    {
        $service = $this->services($r,null,null,null,300000 + (int)substr($r->candidate->manifest->toWire()->get('generationId'),-3)*100);
        $service->staging->stage($r->lease,$r->candidate);
        $index = new PdoLexicalIndex($this->db(),$service->tx,PortFixture::validator(),new CanonicalChunkSets($r->candidate->sets),new TestIds(random_int(1000000,9000000)));
        $service->tx->run(function() use ($service,$r,$index): void {
            $service->tx->lockTenant($r->lease->scope->tenantId,D\LockMode::Update);
            foreach ($r->candidate->sets as $set) { $index->index(new D\GenerationId($r->candidate->manifest->toWire()->get('generationId')),$set->chunkSet); }
        });
        $service->staging->seal($r->lease,$r->candidate);
    }
    /** A second ingest has a distinct version/revision/chunkset/job, even with the same source bytes. */
    public function second(): array
    {
        $wire = self::data()->get('second'); $set = Manifest::set($wire); $m = $this->manifest; $f = $m->jobs->catalog;
        $tenant = $m->jobs->scope->tenantId; $source = $set->chunkSet->revisionRef->sourceRef;
        $ir = new D\IrDocument($wire->get('ir'),PortFixture::validator()); $m->jobs->resource(320,$ir->canonical());
        $f->tx->run(function() use ($f,$tenant,$source,$set,$ir): void {
            $f->tx->lockTenant($tenant,D\LockMode::Update); $f->tx->lockSources([$source->sourceId],D\LockMode::Update);
            $old = $f->repo->getSourceVersion($tenant,new D\SourceVersionId(self::id(3)));
            $f->repo->insertSourceVersion($tenant,new D\SourceVersion($source,$old->blob,$old->mime,$old->rightsRef,$old->createdAt));
            $storage = new DocumentStorage(new D\DocumentId(self::id(55)),'synthetic-v2',new D\ResourceId(self::id(320)),$f->clock->now(),[],
                ['s1'=>new UnitStorage(new D\Ulid(self::id(171)),new D\ResourceId(self::id(311))), 's2'=>new UnitStorage(new D\Ulid(self::id(172)),new D\ResourceId(self::id(312)))],
                ['b1'=>new D\Ulid(self::id(181)),'b2'=>new D\Ulid(self::id(182))],
                ['a1'=>new AssetStorage(new D\Ulid(self::id(191)),new D\ResourceId(self::id(313)),'source',null)]);
            (new PdoDocumentStore($f->db,$f->tx,$f->outbox,$f->events->create(...)))->save($tenant,$set->chunkSet->revisionRef,$ir,$storage,
                new D\Parents($tenant,[new D\ResourceRef($tenant,'source_version',$source->sourceVersionId)]));
            $metadata = [];
            foreach ($set->chunkSet->chunks as $i=>$chunk) { $metadata[$chunk->ref->chunkId->value] = new ChunkMetadata(new D\ResourceId(self::id(311+$i)),new JsonObject(['kind'=>'verbatim','supportingRefs'=>[]]),[]); }
            $f->repo->insertChunkSet($tenant,$set->chunkSet,new ChunkSetMetadata($set->manifestDigest,$metadata));
        });
        $base = $m->jobs->processing;
        $processing = new D\ProcessingIdentity($source->sourceVersionId,$source->sourceDigest,$set->chunkSet->revisionRef->parserSignature,
            $base->ocrProfileSignature,$set->chunkSet->chunkerSignature,$base->irSchema,$base->indexProfileDigest,null);
        $m->jobs->queue->enqueue(new D\JobCommand($m->jobs->scope,$source,null,new D\JobType('ingest'),$processing,new D\UtcInstant('2026-01-01T00:00:00Z')),new D\IdempotencyKey('ingest-two'));
        $lease = $m->jobs->lease('ingest'); return [$set,$lease];
    }
    public function resolve(D\SnapshotSelector $selector): D\PublicationSnapshot
    {
        $f = $this->manifest->jobs->catalog; $s = $this->manifest->jobs->scope;
        return $f->tx->run(function() use ($f,$s,$selector): D\PublicationSnapshot {
            $f->tx->lockTenant($s->tenantId,D\LockMode::Share); $f->tx->lockKb($s->kbId,D\LockMode::Share);
            return $f->repo->resolveSnapshot($s->tenantId,$s->kbId,$selector,new D\ProfileId(PdoLexicalIndex::PROFILE));
        });
    }
    public static function failure(callable $call, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void { Manifest::failure($call,$code,$outcome); }
    public static function outcome(string $id, mixed $actual): void { Assert::assertSame(Jcs::canonicalize(self::data()->get('outcomes')->get($id)),Jcs::canonicalize($actual),$id); }
}
