<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Adapter\Cli\UsageError;
use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\Postgres\Catalog\ChunkMetadata;
use KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata;
use KnowKit\Adapter\Postgres\Documents\DocumentStorage;
use KnowKit\Adapter\Postgres\Documents\PdoDocumentStore;
use KnowKit\Adapter\Postgres\Documents\UnitStorage;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoReadBackend;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoStagingGenerations;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Adapter\Postgres\Publication\PdoActivation;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Ingest\Text\ParserSettings;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Application\Jobs\CancelJob;
use KnowKit\Application\Jobs\VerifiedCheckpoints;
use KnowKit\Application\Publication\Activate\Publisher;
use KnowKit\Application\Publication\IndexReadabilityProbe;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Application\Publication\Manifest\ProjectionReadiness;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

/** Bounded local TXT host worker. Durable core stores retain ownership, fencing and publication. */
final class TextDelivery
{
    /** @var array<string,D\ChunkingResult> */
    public array $sets = [];
    /** @var array<string,D\IrDocument> */
    public array $documents = [];
    /** @var array<string,D\ActivationRequest> */
    private array $builds = [];
    /** @var array<string,string> */
    private array $checkpoints = [];
    private PdoJobQueue $queue;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $host = $runtime->host;
        $verifier = new VerifiedCheckpoints(
            fn (D\Sha256 $digest): string => $this->checkpoints[$digest->value]
                ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'checkpoint_unavailable'),
            $runtime->storage->openResource(...));
        $this->queue = new PdoJobQueue($host->db, $runtime->tx, $host->scope(), $host,
            ['local_text' => ['text']], $verifier->load(...));
    }

    public function upload(Command $command): Response
    {
        $path = $command->uploadPath;
        if ($path === null || str_contains($path, '://') || !is_file($path)) {
            throw new UsageError();
        }
        $bytes = @file_get_contents($path, false, null, 0, 1048577);
        if (!is_string($bytes) || strlen($bytes) > 1048576) {
            throw new UsageError();
        }
        $r = $this->runtime;
        $scope = $r->host->scope();
        $ref = new D\SourceVersionRef($scope->tenantId, new D\SourceId($command->text('sourceId')),
            new D\SourceVersionId($command->text('sourceVersionId')), new D\SourceDigest(hash('sha256', $bytes)));
        $old = $r->catalog->getSourceVersion($scope->tenantId, $ref->sourceVersionId);
        if ($old !== null && $old->ref != $ref) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $r->authority->prepare($scope, new D\ResourceRef($scope->tenantId, 'source', $ref->sourceId), D\Operation::SourceVersionAdd);
        // Admission before writing; acceptVersion repeats current authorization under authoritative SQL guards.
        $r->host->decide($scope, new D\ResourceRef($scope->tenantId, 'source', $ref->sourceId), D\Operation::SourceVersionAdd)
            ->requireAllow($scope, new D\ResourceRef($scope->tenantId, 'source', $ref->sourceId), D\Operation::SourceVersionAdd,
                $r->host->now(), new D\Sha256(HashDomain::PolicyBinding->digest($r->host->binding([$ref->sourceId->value]))));
        $blob = $old->blob ?? $r->storage->put($ref->sourceId, $bytes, $command->epoch(), 'original:' . $ref->sourceVersionId->value);
        $version = $r->sources->acceptVersion($scope, new D\SourceVersion($ref, $blob, 'text/plain',
            $command->text('rightsRef'), $old->createdAt ?? $r->host->now()), $r->mutation($command));

        return Runtime::ok($version->ref->toWire());
    }

    public function ingest(Command $command): Response
    {
        if ($command->name === 'ingest status') {
            return $this->status(new D\JobId($command->text('jobId')));
        }
        if ($command->name === 'ingest cancel') {
            $scope = $this->runtime->host->scope();
            $job = $this->job(new D\JobId($command->text('jobId')));
            $this->admit(new D\SourceId($job['source_id']), D\Operation::Ingest);
            $source = $this->runtime->catalog->getSource($scope->tenantId, new D\SourceId($job['source_id']));
            if ($source?->lifecycleEpoch != $command->epoch() || (string) $job['source_epoch'] !== $command->epoch()->value) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_write');
            }
            $cancel = new CancelJob($this->queue, static function (): void {});
            D\DomainFailure::requireWrite($cancel->cancel($scope, new D\JobId($job['id'])));

            return $this->status(new D\JobId($job['id']));
        }

        return $this->start($command);
    }

    private function start(Command $command): Response
    {
        $r = $this->runtime;
        $scope = $r->host->scope();
        $version = $r->catalog->getSourceVersion($scope->tenantId, new D\SourceVersionId($command->text('sourceVersionId')))
            ?? throw new D\DomainFailure(D\Outcome::Denied, 'source_unavailable');
        $source = $r->catalog->getSource($scope->tenantId, $version->ref->sourceId);
        if ($source?->lifecycleEpoch != $command->epoch()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_write');
        }
        $this->admit($version->ref->sourceId, $command->name === 'ingest reprocess' ? D\Operation::Reprocess : D\Operation::Ingest);
        $parser = ParserSettings::profile('text');
        $chunker = new D\ChunkerProfile('local-text', 'structural', '1', new JsonObject(['maxBytes' => 1200, 'overlapBlocks' => 0]));
        $job = $this->enqueue($command, $version, $parser, $chunker);
        $generation = $command->text('generationId');
        if (isset($this->builds[$generation])) {
            if ($this->builds[$generation]->lease->jobId != $job) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'idempotency_conflict');
            }

            return $this->status($job);
        }
        $lease = $this->queue->lease(new D\JobType('local_text'), new D\WorkerId($r->host->next()->value));
        if ($lease === null || $lease->jobId != $job || $lease->sourceEpoch != $command->epoch()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_write');
        }
        $request = $this->build($lease, $version, $parser, $chunker, $generation);
        $this->builds[$generation] = $request;
        $this->checkpoint($request);

        return $this->status($job);
    }

    private function enqueue(Command $command, D\SourceVersion $version, D\ParserProfile $parser, D\ChunkerProfile $chunker): D\JobId
    {
        $processing = new D\ProcessingIdentity($version->ref->sourceVersionId, $version->ref->sourceDigest,
            $parser->signature, new D\ParserSignature('none', '1', new D\Fingerprint(hash('sha256', 'none'))),
            $chunker->signature, 'ir-1.0', new D\Fingerprint(hash('sha256', PdoLexicalIndex::PROFILE)), null);

        return $this->queue->enqueue(new D\JobCommand($this->runtime->host->scope(), $version->ref, null,
            new D\JobType('local_text'), $processing, new D\UtcInstant('2000-01-01T00:00:00Z')), $command->idempotencyKey());
    }

    private function build(D\Lease $lease, D\SourceVersion $version, D\ParserProfile $parser,
        D\ChunkerProfile $chunker, string $generation): D\ActivationRequest
    {
        $r = $this->runtime;
        $scope = $lease->scope;
        $bytes = $this->readOriginal($version);
        $document = (new TextParser($r->host->validator))->parse(new D\SourceBytes($version->ref, $bytes, 'text/plain'), $parser);
        $revision = new D\DocumentRevisionRef($version->ref, new D\DocumentRevisionId($r->host->next()->value),
            new D\IrDigest(hash('sha256', $document->canonical())), $parser->signature);
        $documentId = new D\DocumentId($r->host->next()->value);
        $set = (new StructuralChunker($revision, $documentId))->build($document, $chunker);
        $this->persist($lease, $documentId, $document, $set);
        $inventory = new ResourceInventory($r->host->db);
        $resources = [];
        foreach ($inventory->resources($scope->tenantId, [$set]) as $resource) {
            if (!$resource instanceof JsonObject) {
                throw new D\DomainFailure(D\Outcome::Unavailable, 'resource_inventory_invalid');
            }
            $resources[] = $resource;
        }
        $candidate = (new Builder($r->host->validator))->build($scope, new D\GenerationId($generation), [$set],
            $resources, $inventory->coverage($scope->tenantId, [$set]));
        $request = new D\ActivationRequest($lease, $candidate, new D\Decimal('0'),
            new D\IdempotencyKey('candidate-' . $generation));
        [$staging, $index] = $this->publicationServices($request);
        $staging->stage($lease, $candidate);
        $r->tx->run(function () use ($r, $candidate, $index, $set): void {
            $r->tx->lockTenant($r->host->scope()->tenantId, D\LockMode::Update);
            $index->index(new D\GenerationId($candidate->manifest->toWire()->get('generationId')), $set->chunkSet);
        });
        $staging->seal($lease, $candidate);
        $this->sets[$generation] = $set;
        $this->documents[$revision->irDigest->value] = $document;

        return $request;
    }

    private function admit(D\SourceId $source, D\Operation $operation): void
    {
        $host = $this->runtime->host;
        $host->bindingOverride = $host->binding([$source->value]);
        (new PolicyChecks($host, $host, $host))->admission($host->context(),
            [new D\ResourceRef($host->scope()->tenantId, 'source', $source)], $operation, $host->bindingOverride);
        $host->bindingOverride = null;
    }

    private function readOriginal(D\SourceVersion $version): string
    {
        $stream = $this->runtime->storage->blobs->open($version->blob);
        $bytes = '';
        try {
            while (!$stream->eof()) {
                $bytes .= $stream->read(65536);
                if (strlen($bytes) > 1048576) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
                }
            }
        } finally {
            $stream->close();
        }

        return $bytes;
    }

    private function persist(D\Lease $lease, D\DocumentId $documentId, D\IrDocument $document, D\ChunkingResult $set): void
    {
        $r = $this->runtime;
        $identity = $set->chunkSet->revisionRef->documentRevisionId->value;
        $storage = $this->documentStorage($lease, $documentId, $document, $identity);
        $metadata = $this->chunkMetadata($lease, $set, $identity);
        $r->tx->run(function () use ($r, $lease, $document, $set, $storage, $metadata): void {
            $tenant = $lease->scope->tenantId;
            $r->tx->lockTenant($tenant, D\LockMode::Update);
            $r->tx->lockSources([$lease->sourceRef->sourceId], D\LockMode::Update);
            (new PdoDocumentStore($r->host->db, $r->tx, $r->outbox, $r->events->create(...)))->save(
                $tenant, $set->chunkSet->revisionRef, $document, $storage,
                new D\Parents($tenant, [new D\ResourceRef($tenant, 'source_version', $lease->sourceRef->sourceVersionId)]));
            $r->catalog->insertChunkSet($tenant, $set->chunkSet, new ChunkSetMetadata($set->manifestDigest, $metadata));
            $this->registerChunks($set);
        });
    }

    private function documentStorage(D\Lease $lease, D\DocumentId $documentId, D\IrDocument $document, string $identity): DocumentStorage
    {
        $r = $this->runtime;
        $source = $lease->sourceRef->sourceId;
        $ir = $r->storage->put($source, $document->canonical(), $lease->sourceEpoch, $identity . ':ir');
        $segments = $blocks = [];
        foreach ($document->segments() as $segment) {
            $blob = $r->storage->put($source, $segment->get('text'), $lease->sourceEpoch, $identity . ':segment:' . $segment->get('segmentId'));
            $segments[$segment->get('segmentId')] = new UnitStorage($r->host->next(), new D\ResourceId($blob->objectVersion));
        }
        foreach ($document->toWire()->get('blocks') as $block) {
            $blocks[$block->get('blockId')] = $r->host->next();
        }

        return new DocumentStorage($documentId, 'uploaded-text', new D\ResourceId($ir->objectVersion),
            $r->host->now(), [], $segments, $blocks, []);
    }

    /** @return array<string,ChunkMetadata> */
    private function chunkMetadata(D\Lease $lease, D\ChunkingResult $set, string $identity): array
    {
        $metadata = [];
        foreach ($set->chunkSet->chunks as $chunk) {
            $blob = $this->runtime->storage->put($lease->sourceRef->sourceId, $chunk->text(), $lease->sourceEpoch,
                $identity . ':chunk:' . $chunk->ref->chunkId->value);
            $metadata[$chunk->ref->chunkId->value] = new ChunkMetadata(new D\ResourceId($blob->objectVersion),
                $set->provenance($chunk->ref->chunkId->value), $set->structurePath($chunk->ref->chunkId->value));
        }

        return $metadata;
    }

    private function registerChunks(D\ChunkingResult $set): void
    {
        $r = $this->runtime;
        $tenant = $set->chunkSet->revisionRef->sourceRef->tenantId;
        foreach ($set->chunkSet->chunks as $chunk) {
            $resource = new D\ResourceRef($tenant, 'chunk', $chunk->ref->chunkId);
            $identity = (new \KnowKit\Adapter\Postgres\Dependencies\NodeMetadata($r->host->db, $r->tx))->resolve($resource)['identity'];
            $r->dependencies->register(new D\Node($resource, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity))),
                new D\Parents($tenant, [new D\ResourceRef($tenant, 'document_revision', $set->chunkSet->revisionRef->documentRevisionId)]));
        }
    }

    /** @return array{PdoStagingGenerations,PdoLexicalIndex,Publisher} */
    private function publicationServices(D\ActivationRequest $request): array
    {
        $r = $this->runtime;
        $canonical = new CanonicalChunkSets($request->candidate->sets);
        $probe = new IndexReadabilityProbe(new PdoReadBackend($r->host->db, $canonical),
            fn (D\TenantId $tenant, JsonObject $ref): \KnowKit\Port\ReadableStream =>
                $r->storage->openResource($tenant, new D\ResourceId($ref->get('resourceId'))));
        $staging = new PdoStagingGenerations($r->host->db, $r->tx, $r->authority->prepare(...), $r->authority->requireAllow(...),
            new ResourceInventory($r->host->db), $probe->verify(...), $probe->verifyBackend(...),
            (new ProjectionReadiness())->check(...), $r->events->create(...), $r->outbox);
        $store = new PdoActivation($r->host->db, $r->tx, $staging, $r->host, $r->host,
            $r->host->validator, $r->events->create(...), $r->outbox);

        return [$staging, new PdoLexicalIndex($r->host->db, $r->tx, $r->host->validator, $canonical, $r->host),
            new Publisher($store, $r->authority, $probe)];
    }

    public function publication(Command $command): Response
    {
        $base = $this->builds[$command->text('generationId')]
            ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'candidate_unavailable');
        if ($base->lease->sourceEpoch != $command->epoch()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_write');
        }
        [$staging, , $publisher] = $this->publicationServices($base);
        if ($command->name === 'publication validate') {
            $receipt = $staging->seal($base->lease, $base->candidate);

            return Runtime::ok($receipt);
        }
        $request = new D\ActivationRequest($base->lease, $base->candidate,
            $command->expectedRevision ?? throw new UsageError(), $command->idempotencyKey());
        $receipt = $publisher->publish($request);
        D\DomainFailure::requireWrite($this->queue->complete($base->lease,
            new D\JobResult(D\Outcome::Success, new D\Sha256($base->candidate->digest->value))));

        return Runtime::ok($receipt->toWire());
    }

    private function checkpoint(D\ActivationRequest $request): void
    {
        $r = $this->runtime;
        $lease = $request->lease;
        $row = $this->job($lease->jobId);
        $refs = array_map(static fn (JsonObject $ref): JsonObject => $ref->without('kind'), $request->candidate->resources);
        $wire = new JsonObject(['schemaVersion' => 'job-checkpoint-1.0', 'tenantId' => $lease->scope->tenantId->value,
            'kbId' => $lease->scope->kbId->value, 'sourceId' => $lease->sourceRef->sourceId->value,
            'sourceVersionId' => $lease->sourceRef->sourceVersionId->value, 'jobId' => $lease->jobId->value,
            'sourceEpoch' => $lease->sourceEpoch->value, 'processingDigest' => $row['processing_digest'],
            'stage' => $row['stage'], 'inputDigest' => $row['stage_input_digest'],
            'outputDigest' => HashDomain::ResourceInventory->digest($refs), 'uncertainExternalEffect' => false, 'resources' => $refs]);
        $manifest = new D\JobCheckpointManifest($wire);
        $r->storage->put($lease->sourceRef->sourceId, Jcs::canonicalize($wire), $lease->sourceEpoch, 'checkpoint:' . $manifest->digest()->value);
        $this->checkpoints[$manifest->digest()->value] = Jcs::canonicalize($wire);
        D\DomainFailure::requireWrite($this->queue->checkpoint($lease, $manifest->checkpoint()));
    }

    private function job(D\JobId $id): array
    {
        $scope = $this->runtime->host->scope();
        $row = $this->runtime->host->db->row('SELECT * FROM jobs WHERE tenant_id=? AND kb_id=? AND id=? AND scope_json->>\'hostScopeHash\'=?',
            [$scope->tenantId->value, $scope->kbId->value, $id->value, $scope->hostScopeHash->value]);

        return $row ?? throw new D\DomainFailure(D\Outcome::Denied, 'job_unavailable');
    }

    private function status(D\JobId $id): Response
    {
        $row = $this->job($id);
        $this->admit(new D\SourceId($row['source_id']), D\Operation::Ingest);

        return Runtime::ok(new JsonObject(['jobId' => $id->value, 'state' => $row['state'],
            'stage' => $row['stage'], 'completedStages' => (int) $row['completed_stage_count'],
            'uncertainExternalEffect' => (bool) $row['uncertain_external_effect']]));
    }
}
