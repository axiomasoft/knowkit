<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\LocalBlob\LocalBlobStore;
use KnowKit\Adapter\Postgres\Catalog\ChunkMetadata;
use KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogRepository;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Documents\AssetStorage;
use KnowKit\Adapter\Postgres\Documents\DocumentStorage;
use KnowKit\Adapter\Postgres\Documents\PdoDocumentStore;
use KnowKit\Adapter\Postgres\Documents\UnitStorage;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoReadBackend;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoStagingGenerations;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Adapter\Postgres\Publication\PdoActivation;
use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Events\DomainEventFactory;
use KnowKit\Application\Ingest\Worker\WorkerArtifactImporter;
use KnowKit\Application\Ingest\Worker\WorkerParserClient;
use KnowKit\Application\Jobs\VerifiedCheckpoints;
use KnowKit\Application\Lifecycle\LifecycleCommands;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Application\Publication\Manifest\ProjectionReadiness;
use KnowKit\Application\Resources\Upload;
use KnowKit\Application\Resources\ReadAuthority;
use KnowKit\Application\Validation\DefaultQualityPolicy;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port as P;
use KnowKit\Registry\WorkerProfiles;
use KnowKit\Tests\Jobs\Leases\Bytes;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Storage\Catalog\CurrentOwner;
use KnowKit\Tests\Storage\Catalog\TestClock;
use KnowKit\Tests\Storage\Catalog\TestIds;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;

/** Host composition using production ports/adapters. Only phase hooks are test instrumentation. */
final class Pipeline
{
    public readonly PdoJobQueue $queue;
    public readonly BlobStorage $blobs;
    public readonly D\ChunkerProfile $chunker;
    private readonly TestIds $ids;
    private readonly LeaseHeartbeat $heartbeat;

    public function __construct(
        public readonly Catalog $catalog,
        public readonly D\Scope $scope,
        public readonly string $directory,
        string $blobRoot,
        private readonly ?\Closure $phase = null,
        ?\Closure $monotonic = null,
    ) {
        $this->ids = new TestIds(random_int(10000000, 90000000));
        $this->blobs = new BlobStorage($catalog, $scope, $blobRoot);
        $this->chunker = new D\ChunkerProfile('e2e-structural-v1', 'structural', '1',
            new JsonObject(['maxBytes' => 32768, 'overlapBlocks' => 0]));
        $verified = new VerifiedCheckpoints(
            fn (D\Sha256 $digest): string => file_get_contents($directory . '/manifest-' . $digest->value),
            fn (D\TenantId $tenant, D\ResourceId $id): P\ReadableStream => $this->open($tenant, $id),
        );
        $this->queue = new PdoJobQueue($catalog->db, $catalog->tx, $scope, $this->ids,
            ['ingest' => ['extract', 'chunk', 'index']], $verified->load(...));
        $this->heartbeat = new LeaseHeartbeat($this->queue, $monotonic);
    }

    public function signal(string $phase): void
    {
        if ($this->phase !== null) {
            ($this->phase)($phase);
        }
    }

    public function lifecycle(): LifecycleCommands
    {
        $f = $this->catalog;
        $dependencies = new PdoDependencyStore($f->db, $f->tx, $this->scope->tenantId, $this->ids);
        $store = new PdoLifecycleStore($f->db, $f->tx, $this->scope->tenantId, $f->clock, $this->ids,
            Protocol::validator(), $f->events->create(...), $dependencies, $f->outbox, $f->authority->requireAllow(...));

        return new LifecycleCommands($store, $f->repo, $f->tx, $f->authority, $f->repo->acceptSourceVersion(...));
    }

    public function extract(D\Lease $lease, D\ProcessingIdentity $identity, D\ParserProfile $profile,
        D\SourceBytes $source, P\WorkerRunner $runner, int $revision = 701): D\WorkerAcceptance
    {
        $profiles = new WorkerProfiles();
        $enabled = [D\Capability::ParserText, D\Capability::ParserImages];
        if ($profile->id === 'docling-pdf-ocr-eng-v1') {
            $enabled[] = D\Capability::ParserOcr;
        }
        $caps = new D\ParserCapabilities($enabled, ['ir-1.0']);
        $profiles->register($profile, $caps, true);
        $ledger = $this->blobs->ledger;
        $importer = new WorkerArtifactImporter($this->blobs->store, $this->ids,
            function (D\BlobWriteReservation $reservation, D\JobId $job) use ($ledger): D\BlobWriteReservation {
                $stored = $ledger->reserveWriter($reservation, $job);
                $this->signal('before-upload');

                return $stored;
            }, function (D\BlobRef $ref) use ($ledger): void {
                $ledger->requireAccepted($ref);
                $this->signal('after-upload');
            }, $ledger->markUncertain(...), function (D\JobCheckpointManifest $manifest): void {
                Jobs::durableFile($this->directory . '/manifest-' . $manifest->digest()->value, Jcs::canonicalize($manifest->toWire()));
                $this->signal('before-checkpoint');
            });
        $client = new WorkerParserClient($runner, $this->queue, $profiles, $importer, Protocol::validator(),
            new DefaultQualityPolicy(), function (D\Lease $current, D\ParserProfile $parser): void {
                $this->catalog->authority->prepare($current->scope,
                    new D\ResourceRef($current->scope->tenantId, 'source', $current->sourceRef->sourceId), D\Operation::Ingest);
                $this->heartbeat->tick($current);
            });
        $accepted = $client->execute($lease, $source, $profile, $identity, new D\DocumentRevisionId(Jobs::id($revision)),
            new D\WorkerLimits(55, 45, 2147483648, 4294967296, 500, 67108864, 126),
            new D\CoveragePolicy(), new D\Ulid(Jobs::id(702)), [D\Capability::ParserText]);
        $this->signal('after-checkpoint');

        return $accepted;
    }

    public function persist(D\Lease $lease, D\DocumentRevisionRef $revision, D\WorkerOutput $output): D\ChunkingResult
    {
        $ir = $output->ir;
        $documentId = new D\DocumentId(Jobs::id((int) substr($revision->documentRevisionId->value, 3) + 4));
        $set = (new StructuralChunker($revision, $documentId))->build($ir, $this->chunker);
        $pages = $segments = $blocks = $assets = $metadata = [];
        foreach (['pages' => 'pageId', 'segments' => 'segmentId'] as $collection => $key) {
            foreach ($ir->toWire()->get($collection) as $unit) {
                ${$collection}[$unit->get($key)] = new UnitStorage($this->ids->next(), $this->put($lease, $unit->get('text')));
            }
        }
        foreach ($ir->toWire()->get('blocks') as $block) {
            $blocks[$block->get('blockId')] = $this->ids->next();
        }
        foreach ($ir->assets() as $asset) {
            $assets[$asset->get('assetId')] = new AssetStorage($this->ids->next(),
                $this->resource($asset->get('digest'), $lease->sourceRef->sourceId), 'source', null);
        }
        foreach ($set->chunkSet->chunks as $chunk) {
            $id = $chunk->ref->chunkId->value;
            $metadata[$id] = new ChunkMetadata($this->put($lease, $chunk->text()),
                $set->provenance($id), $set->structurePath($id));
        }
        $storage = new DocumentStorage($documentId, 'e2e-document',
            $this->resource($revision->irDigest->value, $lease->sourceRef->sourceId), $this->catalog->clock->now(), $pages, $segments, $blocks, $assets);
        $f = $this->catalog;
        $f->tx->run(function () use ($f, $lease, $revision, $ir, $storage, $set, $metadata): void {
            $f->tx->lockTenant($this->scope->tenantId, D\LockMode::Update);
            $f->tx->lockSources([$lease->sourceRef->sourceId], D\LockMode::Update);
            (new PdoDocumentStore($f->db, $f->tx, $f->outbox, $f->events->create(...)))->save($this->scope->tenantId,
                $revision, $ir, $storage, new D\Parents($this->scope->tenantId,
                    [new D\ResourceRef($this->scope->tenantId, 'source_version', $lease->sourceRef->sourceVersionId)]));
            $f->repo->insertChunkSet($this->scope->tenantId, $set->chunkSet, new ChunkSetMetadata($set->manifestDigest, $metadata));
        });
        $this->checkpointStage($lease, 'chunk', [$storage->irResourceId]);

        return $set;
    }

    public function request(D\Lease $lease, D\ChunkingResult $set, int $generation = 750, string $pointer = '0'): D\ActivationRequest
    {
        $inventory = new ResourceInventory($this->catalog->db);
        $candidate = (new Builder(Protocol::validator()))->build($this->scope, new D\GenerationId(Jobs::id($generation)), [$set],
            $inventory->resources($this->scope->tenantId, [$set]), $inventory->coverage($this->scope->tenantId, [$set]));

        return new D\ActivationRequest($lease, $candidate, new D\Decimal($pointer), new D\IdempotencyKey('e2e-publish-' . $generation));
    }

    public function publication(D\ActivationRequest $request): array
    {
        $f = $this->catalog;
        $backend = new PdoReadBackend($f->db, new CanonicalChunkSets($request->candidate->sets));
        $probe = new IndexReadabilityProbe($backend,
            fn (D\TenantId $tenant, JsonObject $ref): P\ReadableStream => $this->open($tenant, new D\ResourceId($ref->get('resourceId'))));
        $staging = new PdoStagingGenerations($f->db, $f->tx, $f->authority->prepare(...), $f->authority->requireAllow(...),
            new ResourceInventory($f->db), $probe->verify(...), $probe->verifyBackend(...),
            (new ProjectionReadiness())->check(...), $f->events->create(...), $f->outbox);
        $events = new DomainEventFactory($f->clock, $this->ids, Protocol::validator());
        $outbox = new BarrierOutbox($f->outbox, $this->signal(...));
        $activation = new PdoActivation($f->db, $f->tx, $staging, $f->clock, $this->ids,
            Protocol::validator(), $events->create(...), $outbox);

        return [$staging, new Publisher($activation, $f->authority, $probe)];
    }

    public function seal(D\ActivationRequest $request): void
    {
        [$staging] = $this->publication($request);
        $staging->stage($request->lease, $request->candidate);
        $index = $this->index($request->candidate->sets);
        $this->catalog->tx->run(function () use ($request, $index): void {
            $this->catalog->tx->lockTenant($this->scope->tenantId, D\LockMode::Update);
            foreach ($request->candidate->sets as $set) {
                $index->index(new D\GenerationId($request->candidate->manifest->toWire()->get('generationId')), $set->chunkSet);
            }
        });
        $staging->seal($request->lease, $request->candidate);
        $this->checkpointStage($request->lease, 'index',
            [new D\ResourceId($request->candidate->resources[0]->get('resourceId'))]);
    }

    public function publish(D\ActivationRequest $request): D\PublishReceipt
    {
        [, $publisher] = $this->publication($request);
        $receipt = $publisher->publish($request);
        if (!$this->queue->complete($request->lease, new D\JobResult(D\Outcome::Success,
            new D\Sha256($receipt->toWire()->get('manifestDigest')),
            new D\Coverage($request->candidate->manifest->toWire()->get('coverage'))))) {
            throw new \RuntimeException('Published job completion rejected');
        }
        $this->signal('after-publication');

        return $receipt;
    }

    public function index(array $sets): PdoLexicalIndex
    {
        return new PdoLexicalIndex($this->catalog->db, $this->catalog->tx, Protocol::validator(),
            new CanonicalChunkSets($sets), $this->ids);
    }

    private function checkpointStage(D\Lease $lease, string $stage, array $ids): void
    {
        $context = $this->queue->workerContext($lease) ?? throw new \RuntimeException('Current lease required');
        if ($context->stage->value !== $stage) {
            throw new \RuntimeException('Unexpected ingest stage');
        }
        $resources = [];
        foreach ($ids as $id) {
            $row = $this->catalog->db->row("SELECT digest,byte_length FROM resource_ledger WHERE tenant_id=? AND source_id=? AND id=? AND state='accepted'",
                [$this->scope->tenantId->value, $lease->sourceRef->sourceId->value, $id->value]);
            if ($row === null) {
                throw new \RuntimeException('Missing stage resource');
            }
            $resources[] = new JsonObject(['resourceId' => $id->value, 'digest' => $row['digest'], 'byteLength' => (string) $row['byte_length']]);
        }
        $manifest = new D\JobCheckpointManifest(new JsonObject([
            'schemaVersion' => 'job-checkpoint-1.0', 'tenantId' => $this->scope->tenantId->value,
            'kbId' => $this->scope->kbId->value, 'sourceId' => $lease->sourceRef->sourceId->value,
            'sourceVersionId' => $lease->sourceRef->sourceVersionId->value, 'jobId' => $lease->jobId->value,
            'sourceEpoch' => $lease->sourceEpoch->value, 'processingDigest' => $context->processingDigest->value,
            'stage' => $stage, 'inputDigest' => $context->inputDigest->value,
            'outputDigest' => HashDomain::ResourceInventory->digest($resources),
            'uncertainExternalEffect' => false, 'resources' => $resources,
        ]));
        Jobs::durableFile($this->directory . '/manifest-' . $manifest->digest()->value, Jcs::canonicalize($manifest->toWire()));
        if (!$this->queue->checkpoint($lease, $manifest->checkpoint())) {
            throw new \RuntimeException('Stage checkpoint rejected');
        }
    }

    public function put(D\Lease $lease, string $bytes): D\ResourceId
    {
        $reservation = new D\BlobWriteReservation(new D\ResourceRef($this->scope->tenantId, 'resource', new D\ResourceId($this->ids->next()->value)),
            $lease->sourceRef->sourceId, new D\WriterId($this->ids->next()->value), $lease->fence, $lease->sourceEpoch,
            null, null, $this->ids->next());
        $ledger = $this->blobs->ledger;
        (new Upload($this->blobs->store, $ledger->reserveWriter(...), $ledger->requireAccepted(...), $ledger->markUncertain(...)))
            ->put($reservation, new Bytes($bytes));

        return new D\ResourceId($reservation->resource->id->value);
    }

    public function resource(string $digest, D\SourceId $source): D\ResourceId
    {
        $id = $this->catalog->db->execute("SELECT id FROM resource_ledger WHERE tenant_id=? AND source_id=? AND digest=? AND state='accepted' ORDER BY id LIMIT 1",
            [$this->scope->tenantId->value, $source->value, $digest])->fetchColumn();

        return new D\ResourceId($id ?: throw new \RuntimeException('Missing accepted resource'));
    }

    public function open(D\TenantId $tenant, D\ResourceId $id): P\ReadableStream
    {
        $this->scope->assertTenant($tenant);
        if ($this->catalog->db->pdo->inTransaction()) {
            throw new \LogicException('Object I/O under SQL guard');
        }
        $row = $this->catalog->db->row('SELECT * FROM resource_ledger WHERE tenant_id=? AND id=?', [$tenant->value, $id->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unknown, 'resource_missing');
        }

        return $this->blobs->store->open(new D\BlobRef($tenant, new D\SourceId($row['source_id']), new D\Sha256($row['digest']),
            new D\Uint64((string) $row['byte_length']), $row['storage_id'], $row['object_version'], $row['owner_kind']));
    }

    public function replay(D\Lease $lease, D\ProcessingIdentity $identity): D\WorkerOutput
    {
        $checkpoint = $this->queue->resume($lease, $identity)
            ?? throw new \RuntimeException('Expected verified checkpoint');
        $manifest = StrictJson::decode(file_get_contents($this->directory . '/manifest-' . $checkpoint->digest->value));
        $bodies = [];
        foreach ($manifest->get('resources') as $resource) {
            $bodies[$resource->get('digest')] = $this->readResource(new D\ResourceId($resource->get('resourceId')));
        }
        $result = null;
        foreach ($bodies as $bytes) {
            if (str_starts_with($bytes, '{')) {
                $wire = StrictJson::decode($bytes);
                if ($wire->get('schemaVersion') === 'worker-result-1.0') {
                    $result = new D\WorkerResult($wire, Protocol::validator());
                }
            }
        }
        if ($result === null) {
            throw new \RuntimeException('Checkpoint lacks terminal result');
        }
        $ir = new D\IrDocument(StrictJson::decode($bodies[$result->irDigest()]), Protocol::validator());
        $assets = [];
        foreach ($result->toWire()->get('assets') as $asset) {
            $assets[$asset->get('relativePath')] = $bodies[$asset->get('digest')];
        }

        // Replay rehydrates verified artifacts; it does not perform a fresh worker negotiation.
        return new D\WorkerOutput($result, $ir, $assets);
    }

    private function readResource(D\ResourceId $id): string
    {
        $stream = $this->open($this->scope->tenantId, $id);
        $bytes = '';
        try {
            while (true) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    if (!$stream->eof()) {
                        throw new \RuntimeException('Resource stream stalled');
                    }

                    return $bytes;
                }
                $bytes .= $chunk;
                if (strlen($bytes) > 67108864) {
                    throw new \RuntimeException('Resource exceeds replay bound');
                }
                if ($stream->eof()) {
                    return $bytes;
                }
            }
        } finally {
            $stream->close();
        }
    }
}

/** Reconnectable test host, same production repositories and policy as CatalogFixture. */
final class Catalog
{
    public readonly PdoCatalogTransaction $tx;
    public readonly TestClock $clock;
    public readonly TestIds $ids;
    public readonly MutationAuthorizer $authority;
    public readonly PdoOutbox $outbox;
    public readonly DomainEventFactory $events;
    public readonly PdoCatalogRepository $repo;

    public function __construct(
        public readonly Connection $db,
    )
    {
        $this->tx = new PdoCatalogTransaction($db);
        $this->clock = new TestClock();
        $this->ids = new TestIds(random_int(10000000, 90000000));
        $this->authority = new MutationAuthorizer(new CurrentOwner($db), $this->clock);
        $this->outbox = new PdoOutbox($db, $this->tx);
        $this->events = new DomainEventFactory($this->clock, $this->ids, Protocol::validator());
        $this->repo = new PdoCatalogRepository($db, $this->tx, $this->authority->requireAllow(...), $this->clock, $this->ids,
            $this->events->create(...), $this->outbox, str_repeat('test cursor authentication ', 2));
    }
}

final class BlobStorage
{
    public readonly PdoResourceLedger $ledger;
    public readonly LocalBlobStore $store;

    public function __construct(
        Catalog $catalog,
        D\Scope $scope,
        public readonly string $root,
    )
    {
        $this->ledger = new PdoResourceLedger($catalog->db, $catalog->tx, $scope->tenantId, 'local-v1', $catalog->clock);
        $callbacks = [];
        foreach (['inspectWriter', 'finalizeIdentity', 'closeWriter', 'markUncertain', 'requireAccepted',
            'listUnreferenced', 'quarantine', 'recoveryRef', 'reconcileWriter'] as $method) {
            $callbacks[$method] = $this->ledger->$method(...);
        }
        $authority = new ReadAuthority($scope, new CurrentOwner($catalog->db), $catalog->clock, $this->ledger->readBinding(...));
        $this->store = new LocalBlobStore($scope->tenantId, $root, 'local-v1', 67108864, $callbacks,
            $authority->requireAllow(...), $this->ledger->deletionBinding(...), $catalog->ids, $catalog->clock, Protocol::validator());
    }
}

final readonly class BarrierOutbox implements P\Outbox
{
    public function __construct(
        private P\Outbox $inner,
        private \Closure $phase,
    ) {
    }

    public function append(D\DomainEvent $event): void
    {
        $this->inner->append($event);
        if ($event->toWire()->get('type') === 'snapshot.activated') {
            ($this->phase)('before-publication-commit');
        }
    }
}
