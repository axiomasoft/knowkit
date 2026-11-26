<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Manifest;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Catalog\{ChunkMetadata, ChunkSetMetadata};
use KnowKit\Adapter\Postgres\Documents\{AssetStorage, DocumentStorage, PdoDocumentStore, UnitStorage};
use KnowKit\Adapter\Postgres\IndexGenerations\{PdoReadBackend, PdoStagingGenerations, ResourceInventory};
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\{Builder, ProjectionReadiness};
use KnowKit\Domain\ManifestCandidate as Candidate;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{JsonObject, StrictJson};
use KnowKit\Port\{GenerationReadBackend, Outbox, ReadableStream};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Jobs\Leases\{Bytes, Fixture as Jobs};
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';

final class Fixture
{
    public D\IrDocument $ir;
    public D\ChunkingResult $set;
    public ResourceInventory $inventory;
    public PdoLexicalIndex $lexical;
    public PdoReadBackend $backend;
    public D\Lease $lease;
    public int $reads = 0;
    public function __construct(public Jobs $jobs, ?JsonObject $coverage = null)
    {
        $f = $jobs->catalog; $tenant = $jobs->scope->tenantId; $wire = self::data();
        if ($coverage !== null) {
            $ir = $wire->get('ir')->with('coverage',$coverage);
            $sidecar = $wire->get('chunkSetManifest');
            $wire = $wire->with('ir',$ir)->with('chunkSetManifest',$sidecar->with('revisionRef',
                $sidecar->get('revisionRef')->with('irDigest',hash('sha256',\KnowKit\Domain\Contract\Jcs::canonicalize($ir)))));
        }
        $this->ir = new D\IrDocument($wire->get('ir'), PortFixture::validator()); $this->set = self::set($wire);
        $f->db->execute('INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')',
            [self::id(1), self::id(99), self::id(2), self::id(100)]);
        $jobs->resource(310, $this->ir->canonical());
        foreach ($this->ir->toWire()->get('segments') as $i=>$segment) { $jobs->resource(311+$i, $segment->get('text')); }
        $jobs->resource(313, base64_decode($wire->get('assetBase64'), true));
        $storage = new DocumentStorage(new D\DocumentId(self::id(5)), 'synthetic', new D\ResourceId(self::id(310)), $f->clock->now(), [],
            ['s1'=>new UnitStorage(new D\Ulid(self::id(71)), new D\ResourceId(self::id(311))),
             's2'=>new UnitStorage(new D\Ulid(self::id(72)), new D\ResourceId(self::id(312)))],
            ['b1'=>new D\Ulid(self::id(81)), 'b2'=>new D\Ulid(self::id(82))],
            ['a1'=>new AssetStorage(new D\Ulid(self::id(91)), new D\ResourceId(self::id(313)), 'source', null)]);
        $f->tx->run(function() use ($f, $tenant, $storage): void {
            $f->tx->lockTenant($tenant, D\LockMode::Update); $f->tx->lockSources([new D\SourceId(self::id(2))], D\LockMode::Update);
            (new PdoDocumentStore($f->db, $f->tx, $f->outbox, $f->events->create(...)))->save($tenant, $this->set->chunkSet->revisionRef, $this->ir,
                $storage, new D\Parents($tenant, [new D\ResourceRef($tenant, 'source_version', new D\SourceVersionId(self::id(3)))]));
            $metadata = [];
            foreach ($this->set->chunkSet->chunks as $i=>$chunk) {
                $metadata[$chunk->ref->chunkId->value] = new ChunkMetadata(new D\ResourceId(self::id(311+$i)),
                    new JsonObject(['kind'=>'verbatim','supportingRefs'=>[]]), []);
            }
            $f->repo->insertChunkSet($tenant, $this->set->chunkSet, new ChunkSetMetadata($this->set->manifestDigest, $metadata));
        });
        $canonical = new CanonicalChunkSets([$this->set]);
        $this->inventory = new ResourceInventory($f->db); $this->backend = new PdoReadBackend($f->db, $canonical);
        $this->lexical = new PdoLexicalIndex($f->db, $f->tx, PortFixture::validator(), $canonical, $f->ids);
        $base=$jobs->processing; $source=$this->set->chunkSet->revisionRef->sourceRef;
        $processing=new D\ProcessingIdentity($source->sourceVersionId,$source->sourceDigest,$this->set->chunkSet->revisionRef->parserSignature,
            $base->ocrProfileSignature,$this->set->chunkSet->chunkerSignature,$base->irSchema,$base->indexProfileDigest,null);
        $jobs->queue->enqueue(new D\JobCommand($jobs->scope,$source,null,new D\JobType('ingest'),$processing,new D\UtcInstant('2026-01-01T00:00:00Z')),new D\IdempotencyKey('manifest'));
        $this->lease = $jobs->lease('ingest');
    }
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function id(int $n): string { return PortFixture::id($n); }
    public static function data(): JsonObject
    { static $data=null; return $data ??= StrictJson::decode((string)file_get_contents(__DIR__ . '/fixtures/v1/cases.json')); }
    public static function set(?JsonObject $data = null): D\ChunkingResult
    {
        $data ??= self::data(); $sidecar = $data->get('chunkSetManifest'); $wire = $sidecar->get('revisionRef');
        $source = new D\SourceVersionRef(new D\TenantId($wire->get('tenantId')), new D\SourceId($wire->get('sourceId')),
            new D\SourceVersionId($wire->get('sourceVersionId')), new D\SourceDigest($wire->get('sourceDigest')));
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId($wire->get('documentRevisionId')),
            new D\IrDigest($wire->get('irDigest')), PortFixture::signature($wire->get('parserSignature')));
        $setId = new D\ChunkSetId($sidecar->get('chunkSetId')); $chunks = [];
        foreach ($sidecar->get('chunks') as $i=>$chunk) {
            $span = $chunk->get('spans')[0];
            $chunks[] = new D\Chunk(new D\ChunkRef($revision, $setId, new D\ChunkId($chunk->get('chunkId')), new D\Sha256($chunk->get('chunkDigest'))),
                new D\DocumentId($sidecar->get('documentId')), [new D\Span(D\TextLocator::segment(new D\LocalId($span->get('segmentId')),
                    new D\LocalId($span->get('blockId')), $span->get('byteStart'), $span->get('byteEnd')), new D\Sha256($span->get('textDigest')))],
                $data->get('ir')->get('segments')[$i]->get('text'));
        }
        return new D\ChunkingResult(new D\ChunkSet($revision, $setId, PortFixture::signature($sidecar->get('chunkerSignature')), $chunks), $sidecar);
    }
    public static function candidateValue(?JsonObject $wire = null): Candidate
    {
        $wire ??= self::data()->get('manifest');
        return new Candidate(new D\IndexManifest($wire, PortFixture::validator()), [self::set()], $wire->get('resources'));
    }
    public function candidate(int $generation = 501): Candidate
    {
        $sets = [$this->set]; $scope = $this->jobs->scope;
        return (new Builder(PortFixture::validator()))->build($scope, new D\GenerationId(self::id($generation)), $sets,
            $this->inventory->resources($scope->tenantId, $sets), $this->inventory->coverage($scope->tenantId, $sets));
    }
    public static function run(callable $run, ?JsonObject $coverage = null): void { Jobs::run(fn(Jobs $jobs) => $run(new self($jobs,$coverage))); }
    public function open(D\TenantId $tenant, JsonObject $resource): ReadableStream
    {
        $this->jobs->scope->assertTenant($tenant);
        if ($this->jobs->catalog->db->pdo->inTransaction()) { throw new \LogicException('Object I/O under SQL lock'); }
        ++$this->reads; $path = $this->jobs->directory . '/resource-' . $resource->get('resourceId');
        if (!is_file($path)) { throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_missing'); }
        return new Bytes(file_get_contents($path));
    }
    public function store(?ProjectionReadiness $optional = null, ?Outbox $outbox = null, ?GenerationReadBackend $backend = null): PdoStagingGenerations
    {
        $f = $this->jobs->catalog;
        $probe = new IndexReadabilityProbe($backend ?? $this->backend, $this->open(...)); $optional ??= new ProjectionReadiness();
        return new PdoStagingGenerations($f->db, $f->tx, $f->authority->prepare(...), $f->authority->requireAllow(...), $this->inventory,
            $probe->verify(...), $probe->verifyBackend(...), $optional->check(...), $f->events->create(...), $outbox ?? $f->outbox);
    }
    public function index(Candidate $candidate): void
    {
        $f = $this->jobs->catalog; $tenant = $this->jobs->scope->tenantId;
        $f->tx->run(function() use ($f, $tenant, $candidate): void {
            $f->tx->lockTenant($tenant, D\LockMode::Update);
            $this->lexical->index(new D\GenerationId($candidate->manifest->toWire()->get('generationId')), $this->set->chunkSet);
        });
    }
    public function state(int $generation = 501): string
    { return $this->jobs->catalog->db->execute('SELECT state FROM index_generations WHERE tenant_id=? AND id=?', [self::id(1),self::id($generation)])->fetchColumn(); }
    public function snapshot(int $generation, int $snapshot, bool $activate = false): void
    {
        $f = $this->jobs->catalog;
        $f->db->execute('INSERT INTO publication_snapshots(tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())',
            [self::id(1),self::id($snapshot),self::id(100),hash('sha256','snapshot-'.$snapshot)]);
        $f->db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)', [self::id(1),self::id($snapshot),self::id(100),self::id($generation),PdoLexicalIndex::PROFILE]);
        $r = $this->set->chunkSet->revisionRef;
        $f->db->execute('INSERT INTO snapshot_memberships VALUES (?,?,?,?,?,?,?,?,?,?)', [self::id(1),$f->ids->next()->value,self::id($snapshot),self::id(100),self::id(2),self::id(3),$r->documentRevisionId->value,$this->set->chunkSet->chunkSetId->value,self::id($generation),PdoLexicalIndex::PROFILE]);
        $f->db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?', [self::id(1),self::id($snapshot)]);
        if ($activate) { $f->db->execute('UPDATE knowledge_bases SET active_snapshot_id=? WHERE tenant_id=? AND id=?', [self::id($snapshot),self::id(1),self::id(100)]); }
    }
    public function pointer(): mixed
    { return $this->jobs->catalog->db->execute('SELECT active_snapshot_id FROM knowledge_bases WHERE tenant_id=? AND id=?', [self::id(1),self::id(100)])->fetchColumn(); }
    public static function failure(callable $run, string $code, D\Outcome $outcome): void
    {
        try { $run(); Assert::fail('Forbidden state accepted'); }
        catch (D\DomainFailure $error) { Assert::assertSame($code, $error->errorCode); Assert::assertSame($outcome, $error->outcome); }
    }
}
