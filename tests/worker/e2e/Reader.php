<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoSelectionCatalog;
use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Reading\PdoReadingStore;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Application\Reading\Readings;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Reading as R;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as Disclosure;
use KnowKit\Tests\Retrieval\Disclosure\Owner;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;
use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;

/** Synthetic host policy; actual persisted search, canonical reads, lineage and disclosure barriers. */
final class Reader
{
    public readonly Host $host;
    public readonly PdoReadingStore $store;
    public readonly Readings $readings;
    public readonly Selections $selections;
    public readonly Disclosures $disclosures;
    public readonly D\QueryBinding $query;
    public readonly Owner $owner;

    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly D\ChunkingResult $set,
        D\IrDocument $ir,
        D\ActivationRequest $request,
        D\PublishReceipt $receipt,
    ) {
        $p = $pipeline;
        $this->host = new Host();
        $this->host->identity = new PolicyContext($p->scope, 'teacher', ['source:read']);
        $this->host->now = Disclosure::databaseNow($p->catalog->db);
        $snapshot = new D\SnapshotRef($p->scope->tenantId, $p->scope->kbId,
            new D\SnapshotId($receipt->toWire()->get('snapshotId')), new D\Fingerprint($receipt->toWire()->get('manifestDigest')));
        $catalog = new PdoSelectionCatalog($p->catalog->db, new CanonicalChunkSets([$set]), Protocol::validator(),
            [$set->chunkSet->revisionRef->irDigest->value => $ir], new CanonicalUnits());
        $this->host->inventory = $catalog->load($p->scope, $snapshot,
            new D\GenerationId($request->candidate->manifest->toWire()->get('generationId')));
        $this->host->eligible = [$set->chunkSet->revisionRef->sourceRef->sourceVersionId];
        $this->host->binding = new JsonObject(['tenantPolicyEpoch' => '0', 'kbPolicyEpoch' => '0',
            'sources' => [new JsonObject(['sourceId' => $set->chunkSet->revisionRef->sourceRef->sourceId->value,
                'sourcePolicyEpoch' => '0', 'lifecycleEpoch' => '0'])]]);
        $this->owner = new Owner($this->host, $p->catalog->db);
        $checks = new PolicyChecks($this->owner, $this->owner, $this->host);
        $dependencies = new PdoDependencyStore($p->catalog->db, $p->catalog->tx, $p->scope->tenantId, $p->catalog->ids);
        $this->registerChunks($dependencies);
        $usages = new PdoUsages($p->catalog->db, $p->catalog->tx, $dependencies);
        $documents = new PdoCanonicalDocuments($p->catalog->db, $p->blobs->store, Protocol::validator());
        $this->selections = new Selections($checks, $p->index([$set]), $catalog,
            new PdoSelections($p->catalog->db, new D\SelectionRecordCodec(Protocol::validator())),
            new BoundedSelector(), $this->host, $this->host, Protocol::validator());
        $this->disclosures = new Disclosures($this->selections, $checks,
            new CanonicalBuffer($catalog, $documents, $this->host), $usages, $this->host, Protocol::validator());
        $this->store = new PdoReadingStore($p->catalog->db, $p->catalog->tx, $usages, $dependencies, Protocol::validator(), $this->host);
        $this->readings = new Readings($this->store, $checks,
            new CanonicalReads($documents, $this->store, new BoundedObjects($p->blobs->store)), $this->host);
        $this->query = $this->host->query(text: str_contains($ir->pages()[0]->get('text'), 'Lifecycle') ? 'Lifecycle' : 'Approved');
    }

    public function select(): D\RetrievalOutcome
    {
        $this->host->now = Disclosure::databaseNow($this->pipeline->catalog->db);

        return $this->host->retrieve($this->query, $this->selections);
    }

    public function disclose(D\RetrievalOutcome $selected): D\DisclosureOutcome
    {
        $refs = array_map(static fn (D\Chunk $chunk): JsonObject => $chunk->ref->toWire(), $this->set->chunkSet->chunks);

        return $this->disclosures->disclose($this->host->context(), $selected->token, $this->query,
            $this->host->inventory->snapshot, new D\DisclosureRequest(new JsonObject([
                'selectionId' => $selected->result->data->id()->value, 'chunkRefs' => [$refs[0]],
                'disclosureAttemptId' => $this->host->next()->value, 'usageContext' => new JsonObject(['runRef' => 'run-1']),
            ]), Protocol::validator()));
    }

    public function read(D\Ulid $citation, R\ReadTarget $target): D\Result
    {
        $this->host->now = Disclosure::databaseNow($this->pipeline->catalog->db);

        return $this->readings->read($this->host->context(), new R\ReadRequest($citation, $this->host->next(), $target,
            D\SelectionLimits::profile('visual'), 8388608, $this->host->now->plusSeconds(30), runRef: 'run-1'));
    }

    private function registerChunks(PdoDependencyStore $dependencies): void
    {
        $p = $this->pipeline;
        $p->catalog->tx->run(function () use ($p, $dependencies): void {
            $p->catalog->tx->lockTenant($p->scope->tenantId, D\LockMode::Update);
            $metadata = new NodeMetadata($p->catalog->db, $p->catalog->tx);
            foreach ($this->set->chunkSet->chunks as $chunk) {
                $ref = new D\ResourceRef($p->scope->tenantId, 'chunk', $chunk->ref->chunkId);
                $identity = HashDomain::DependencyNodeIdentity->digest($metadata->resolve($ref)['identity']);
                $dependencies->register(new D\Node($ref, new D\Fingerprint($identity)), new D\Parents($p->scope->tenantId,
                    [new D\ResourceRef($p->scope->tenantId, 'document_revision', $chunk->ref->revisionRef->documentRevisionId)]));
            }
        });
    }
}
