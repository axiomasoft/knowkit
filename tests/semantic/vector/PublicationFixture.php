<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Postgres\Catalog\ChunkMetadata;
use KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata;
use KnowKit\Adapter\Postgres\Documents\AssetStorage;
use KnowKit\Adapter\Postgres\Documents\DocumentStorage;
use KnowKit\Adapter\Postgres\Documents\PdoDocumentStore;
use KnowKit\Adapter\Postgres\Documents\UnitStorage;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoStagingGenerations;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Adapter\Postgres\Publication\PdoActivation;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\QueryVector;
use KnowKit\PgVector\Vector\PdoGenerationBackend;
use KnowKit\PgVector\Vector\PdoVectorIndex;
use KnowKit\PgVector\Vector\PdoVectorWriter;
use KnowKit\Port\Outbox;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Publication\Manifest\Fixture as Publication;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;

final readonly class PublicationFixture
{
    public PdoGenerationBackend $backend;
    public PdoVectorIndex $index;
    public PdoStagingGenerations $staging;
    public Publisher $publisher;
    public D\Lease $lease;

    /** @param list<D\ChunkingResult>|null $sets */
    public function __construct(
        public Publication $fixture,
        public D\SpaceRef $space,
        ?array $sets = null,
        ?DocumentVectors $documents = null,
        ?Outbox $activationOutbox = null,
    ) {
        $sets ??= [$fixture->set];
        $f = $fixture->jobs->catalog;
        $this->lease = self::lease($fixture, $sets[0], $space);
        $this->backend = new PdoGenerationBackend($f->db, new CanonicalChunkSets($sets), PortFixture::validator(), $space);
        $vectors = [];
        $references = [];
        foreach ($sets as $set) {
            foreach ($set->chunkSet->chunks as $i => $chunk) {
                $vectors[$chunk->ref->chunkId->value] = new D\Vector([$i + 1, 0, 0], $space);
                $references[$chunk->ref->chunkId->value] = $chunk->ref;
            }
        }
        $writer = new PdoVectorWriter($f->db, $f->tx, $this->backend,
            $documents ?? new DocumentVectors($space, $space->documentRole, $vectors, $references), $f->ids);
        $this->index = new PdoVectorIndex($f->db, $f->tx, PortFixture::validator(), $this->backend, $writer,
            static fn ($scope, $query, $requested): QueryVector => QueryVector::bind(
                new D\Vector([0, 0, 0], $space), $requested->queryRole, $scope, $query));
        $probe = new IndexReadabilityProbe($this->backend, $fixture->open(...));
        $this->staging = new PdoStagingGenerations($f->db, $f->tx, $f->authority->prepare(...), $f->authority->requireAllow(...),
            $fixture->inventory, $probe->verify(...), $probe->verifyBackend(...),
            static fn (): JsonObject => new JsonObject(['profile' => ManifestView::PROFILE,
                'capabilities' => new JsonObject(['vector' => new JsonObject(['required' => true, 'outcome' => 'success'])]), 'degraded' => []]),
            $f->events->create(...), $f->outbox);
        $activation = new PdoActivation($f->db, $f->tx, $this->staging, $f->clock, $f->ids,
            PortFixture::validator(), $f->events->create(...), $activationOutbox ?? $f->outbox);
        $this->publisher = new Publisher($activation, $f->authority, $probe);
    }

    private static function lease(Publication $fixture, D\ChunkingResult $set, D\SpaceRef $space): D\Lease
    {
        static $leases = null;
        $leases ??= new \WeakMap();
        $r = $set->chunkSet->revisionRef;
        $base = $fixture->jobs->processing;
        $processing = new D\ProcessingIdentity($r->sourceRef->sourceVersionId, $r->sourceRef->sourceDigest,
            $r->parserSignature, $base->ocrProfileSignature, $set->chunkSet->chunkerSignature,
            'ir-1.0', $base->indexProfileDigest, $space->digest());
        $digest = $processing->digest()->value;
        $known = $leases[$fixture] ?? [];
        if (isset($known[$digest])) {
            return $known[$digest];
        }
        $source = new D\SourceVersionRef($r->sourceRef->tenantId, $r->sourceRef->sourceId,
            $r->sourceRef->sourceVersionId, $r->sourceRef->sourceDigest);
        $fixture->jobs->queue->enqueue(new D\JobCommand($fixture->jobs->scope, $source, null,
            new D\JobType('ingest'), $processing, new D\UtcInstant('2026-01-01T00:00:00Z')), new D\IdempotencyKey('vector-' . $digest));
        $lease = $fixture->jobs->lease('ingest');
        $known[$digest] = $lease;
        $leases[$fixture] = $known;

        return $lease;
    }

    public function candidate(int $generation, ?D\ChunkingResult $set = null): D\ManifestCandidate
    {
        $set ??= $this->fixture->set;
        $scope = $this->fixture->jobs->scope;
        $inventory = new ResourceInventory($this->fixture->jobs->catalog->db);

        return (new Builder(PortFixture::validator()))->build($scope, new D\GenerationId(Fixture::id($generation)), [$set],
            $inventory->resources($scope->tenantId, [$set]), $inventory->coverage($scope->tenantId, [$set]), ManifestView::PROFILE, $this->space);
    }

    public function stage(int $generation, ?D\ChunkingResult $set = null): D\ManifestCandidate
    {
        $candidate = $this->candidate($generation, $set);
        $this->staging->stage($this->lease, $candidate);

        return $candidate;
    }

    public function index(D\ManifestCandidate $candidate): D\IndexReceipt
    {
        $f = $this->fixture->jobs->catalog;

        return $f->tx->run(function () use ($f, $candidate): D\IndexReceipt {
            $f->tx->lockTenant($this->fixture->jobs->scope->tenantId, D\LockMode::Update);

            return $this->index->index(new D\GenerationId($candidate->manifest->toWire()->get('generationId')),
                $candidate->sets[0]->chunkSet, $this->space);
        });
    }

    public function seal(int $generation, ?D\ChunkingResult $set = null): D\ManifestCandidate
    {
        $candidate = $this->stage($generation, $set);
        $this->index($candidate);
        $this->staging->seal($this->lease, $candidate);

        return $candidate;
    }

    public function publish(D\ManifestCandidate $candidate, string $pointer, string $key): D\PublishReceipt
    {
        return $this->publisher->publish(new D\ActivationRequest($this->lease, $candidate,
            new D\Decimal($pointer), new D\IdempotencyKey($key)));
    }

    public function scope(D\PublishReceipt $receipt, int $generation): D\AuthorizedSearchScope
    {
        $s = $this->fixture->jobs->scope;
        $versions = [$this->fixture->set->chunkSet->revisionRef->sourceRef->sourceVersionId];
        $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds' => [], 'allowedSourceVersionIds' => [$versions[0]->value],
            'grantEpoch' => $s->policyEpoch->value, 'policyBindingDigest' => str_repeat('c', 64),
            'expiresAt' => '2026-11-06T12:00:30Z']), PortFixture::validator());
        $snapshot = new D\SnapshotRef($s->tenantId, $s->kbId, new D\SnapshotId($receipt->toWire()->get('snapshotId')),
            new D\Fingerprint($receipt->toWire()->get('manifestDigest')));

        return D\AuthorizedSearchScope::materialize($s, $grant, $snapshot, new D\GenerationId(Fixture::id($generation)),
            $versions, new D\UtcInstant('2026-11-06T12:00:01Z'), $this->space->digest());
    }

    /** Persist a new OCR revision, then a separate chunking axis on that same immutable source. */
    public static function reprocess(Publication $fixture): array
    {
        $data = Publication::data();
        $sidecar = $data->get('chunkSetManifest');
        $parser = $sidecar->get('revisionRef')->get('parserSignature')->with('version', 'ocr-2');
        $ir = new D\IrDocument($data->get('ir')->with('parserSignature', $parser), PortFixture::validator());
        $revision = $sidecar->get('revisionRef')->with('parserSignature', $parser)
            ->with('documentRevisionId', Fixture::id(41))->with('irDigest', hash('sha256', $ir->canonical()));
        $ocrWire = self::sidecar($sidecar->with('revisionRef', $revision)->with('documentId', Fixture::id(51)), 5101, 6101);
        $ocr = Publication::set($data->with('ir', $ir->toWire())->with('chunkSetManifest', $ocrWire));
        $fixture->jobs->resource(410, $ir->canonical());
        $f = $fixture->jobs->catalog;
        $f->db->execute("UPDATE jobs SET lease_until = clock_timestamp() - interval '1 second' WHERE tenant_id=? AND id=?",
            [$fixture->lease->scope->tenantId->value, $fixture->lease->jobId->value]);
        $f->tx->run(static function () use ($f, $ocr, $ir): void {
            $tenant = $ocr->chunkSet->revisionRef->sourceRef->tenantId;
            $f->tx->lockTenant($tenant, D\LockMode::Update);
            $storage = new DocumentStorage(new D\DocumentId(Fixture::id(51)), 'synthetic', new D\ResourceId(Fixture::id(410)), $f->clock->now(), [],
                ['s1' => new UnitStorage(new D\Ulid(Fixture::id(171)), new D\ResourceId(Fixture::id(311))),
                    's2' => new UnitStorage(new D\Ulid(Fixture::id(172)), new D\ResourceId(Fixture::id(312)))],
                ['b1' => new D\Ulid(Fixture::id(181)), 'b2' => new D\Ulid(Fixture::id(182))],
                ['a1' => new AssetStorage(new D\Ulid(Fixture::id(191)), new D\ResourceId(Fixture::id(313)), 'source', null)]);
            (new PdoDocumentStore($f->db, $f->tx, $f->outbox, $f->events->create(...)))->save($tenant,
                $ocr->chunkSet->revisionRef, $ir, $storage,
                new D\Parents($tenant, [new D\ResourceRef($tenant, 'source_version', $ocr->chunkSet->revisionRef->sourceRef->sourceVersionId)]));
            self::insertSet($f, $ocr);
        });
        $chunker = $ocrWire->get('chunkerSignature')->with('version', 'chunk-2');
        $rechunk = Publication::set($data->with('ir', $ir->toWire())
            ->with('chunkSetManifest', self::sidecar($ocrWire->with('chunkerSignature', $chunker), 5201, 6201)));
        $f->tx->run(static function () use ($f, $rechunk): void {
            $f->tx->lockTenant($rechunk->chunkSet->revisionRef->sourceRef->tenantId, D\LockMode::Update);
            self::insertSet($f, $rechunk);
        });

        return [$ocr, $rechunk];
    }

    private static function sidecar(JsonObject $wire, int $set, int $firstChunk): JsonObject
    {
        $entries = [];
        foreach ($wire->get('chunks') as $i => $entry) {
            $entries[] = $entry->with('chunkId', Fixture::id($firstChunk + $i));
        }

        return $wire->with('chunkSetId', Fixture::id($set))->with('chunks', $entries);
    }

    private static function insertSet(CatalogFixture $f, D\ChunkingResult $set): void
    {
        $metadata = [];
        foreach ($set->chunkSet->chunks as $i => $chunk) {
            $metadata[$chunk->ref->chunkId->value] = new ChunkMetadata(new D\ResourceId(Fixture::id(311 + $i)),
                new JsonObject(['kind' => 'verbatim', 'supportingRefs' => []]), []);
        }
        $f->repo->insertChunkSet($set->chunkSet->revisionRef->sourceRef->tenantId, $set->chunkSet,
            new ChunkSetMetadata($set->manifestDigest, $metadata));
    }
}
