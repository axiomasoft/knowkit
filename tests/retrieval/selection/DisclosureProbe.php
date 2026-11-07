<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Selection;

use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;

/** Integration consumer of existing guarded policy/lineage primitives, not a production disclosure API. */
final readonly class DisclosureProbe
{
    private PdoDependencyStore $dependencies;

    public function __construct(
        private CatalogFixture $catalog,
        private Fixture $host,
        private D\SelectionRecord $record,
    ) {
        $this->dependencies = new PdoDependencyStore($catalog->db, $catalog->tx, $record->context->scope->tenantId, $host);
    }

    public function disclose(): D\EvidenceSet
    {
        $s = $this->record->context->scope;
        $ref = $this->record->selection->candidateRefs()[0];
        $prepared = $this->host->checks()->prepare($this->host->context(), [
            new D\ResourceRef($s->tenantId, 'source', $ref->revisionRef->sourceRef->sourceId),
            new D\ResourceRef($s->tenantId, 'source_version', $ref->revisionRef->sourceRef->sourceVersionId),
        ], D\Operation::Disclose, $this->host->binding);

        return $this->catalog->tx->run(function () use ($prepared, $s, $ref): D\EvidenceSet {
            $this->catalog->tx->lockTenant($s->tenantId, D\LockMode::Update);
            $this->catalog->tx->lockKb($s->kbId, D\LockMode::Share);
            $this->catalog->tx->lockSources([$ref->revisionRef->sourceRef->sourceId], D\LockMode::Share);
            $this->record->requireBinding($this->host->identity, $this->record->query, $this->record->snapshot,
                'run-1', $this->host->now);
            $prepared->requireAllow($this->host->context(), $this->host->binding, $this->host->now);
            $this->register($ref);
            $resource = new D\ResourceRef($s->tenantId, 'chunk', $ref->chunkId);
            $report = $this->dependencies->checkAncestors(new D\NodeRefs($s->tenantId, [$resource]));
            if ($report->decision !== D\Decision::Allow) {
                throw new D\DomainFailure(D\Outcome::Denied, 'ancestor_unusable');
            }
            $evidence = $this->evidence($ref);
            $this->recordUsage($evidence, $ref);

            return $evidence;
        });
    }

    private function register(D\ChunkRef $ref): void
    {
        $s = $this->record->context->scope;
        $v = $ref->revisionRef->sourceRef;
        $this->catalog->db->execute('INSERT INTO ' . $this->catalog->db->table('source_version_state')
            . " VALUES (?,?,'usable',NULL,NULL) ON CONFLICT DO NOTHING", [$s->tenantId->value, $v->sourceVersionId->value]);
        $refs = [new D\ResourceRef($s->tenantId, 'source_version', $v->sourceVersionId),
            new D\ResourceRef($s->tenantId, 'document_revision', $ref->revisionRef->documentRevisionId),
            new D\ResourceRef($s->tenantId, 'chunk', $ref->chunkId)];
        $metadata = new NodeMetadata($this->catalog->db, $this->catalog->tx);
        $parents = [];
        foreach ($refs as $resource) {
            $identity = $metadata->resolve($resource)['identity'];
            $node = new D\Node($resource, new D\Fingerprint(HashDomain::DependencyNodeIdentity->digest($identity)));
            $this->dependencies->register($node, new D\Parents($s->tenantId, $parents));
            $parents = [$resource];
        }
    }

    private function evidence(D\ChunkRef $ref): D\EvidenceSet
    {
        $chunk = $this->host->inventory->unit($ref)->chunk;
        $r = $ref->revisionRef;
        $v = $r->sourceRef;
        $citation = new JsonObject([
            'citationId' => $this->host->next()->value, 'tenantId' => $v->tenantId->value,
            'sourceId' => $v->sourceId->value, 'sourceVersionId' => $v->sourceVersionId->value,
            'documentRevisionId' => $r->documentRevisionId->value, 'documentId' => $chunk->documentId->value,
            'sourceDigest' => $v->sourceDigest->value, 'irDigest' => $r->irDigest->value,
            'contentDigest' => $ref->chunkDigest->value, 'origin' => 'source',
            'spans' => array_map(static fn (D\Span $span): JsonObject =>
                $span->locator->toWire()->with('textDigest', $span->textDigest->value), $chunk->spans),
        ]);
        $wire = new JsonObject([
            'tenantId' => $v->tenantId->value, 'selectionId' => $this->record->selection->id()->value,
            'disclosureAttemptId' => $this->host->next()->value, 'usageId' => $this->host->next()->value,
            'hostScopeHash' => $this->record->context->scope->hostScopeHash->value,
            'scopeBindingDigest' => $this->record->selection->scopeBindingDigest()->value,
            'snapshotRef' => $this->record->snapshot->toWire(), 'totalBytes' => strlen($chunk->text()),
            'fragments' => [new JsonObject(['citation' => $citation, 'text' => $chunk->text(), 'joiner' => null,
                'fragmentDigest' => $ref->chunkDigest->value, 'provenanceKind' => 'verbatim'])],
        ]);

        return new D\EvidenceSet($wire->with('evidenceSetDigest', HashDomain::EvidenceSet->digest($wire)), PortFixture::validator());
    }

    private function recordUsage(D\EvidenceSet $evidence, D\ChunkRef $ref): void
    {
        $wire = $evidence->toWire();
        $s = $this->record->context->scope;
        $policyDigest = HashDomain::PolicyBinding->digest($this->host->binding);
        $this->catalog->db->execute('INSERT INTO ' . $this->catalog->db->table('evidence_usages')
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,?,NULL)', [$s->tenantId->value, $wire->get('usageId'),
                $wire->get('disclosureAttemptId'), $s->kbId->value, $s->actorId->value, $s->purpose,
                $this->record->selection->runRef(), $this->record->selection->id()->value,
                $s->hostScopeHash->value, $policyDigest, $this->host->now->value, $wire->get('evidenceSetDigest')]);
        $this->dependencies->recordUsage(new D\Usage(new JsonObject([
            'usageId' => $wire->get('usageId'), 'tenantId' => $s->tenantId->value,
            'disclosureAttemptId' => $wire->get('disclosureAttemptId'), 'selectionId' => $this->record->selection->id()->value,
            'actorId' => $s->actorId->value, 'purpose' => $s->purpose, 'hostScopeHash' => $s->hostScopeHash->value,
            'runRef' => $this->record->selection->runRef(), 'checkedAt' => $this->host->now->value,
            'policyBindingDigest' => $policyDigest, 'status' => 'prepared_disclosure',
            'fragmentRefs' => [new JsonObject(['chunkRef' => $ref->toWire(), 'fragmentDigest' => $ref->chunkDigest->value])],
        ]), PortFixture::validator()));
    }
}
