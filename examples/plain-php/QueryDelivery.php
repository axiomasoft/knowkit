<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Lexical\PdoLexicalIndex;
use KnowKit\Adapter\Lexical\PdoSelectionCatalog;
use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Adapter\Postgres\Reading\PdoReadingStore;
use KnowKit\Adapter\Postgres\Selections\PdoSelections;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Application\Reading\Readings;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\ReferenceCache;

final class QueryDelivery
{
    public ReferenceCache $cache;
    private readonly PdoSelections $records;
    private readonly PdoUsages $usages;
    private readonly PolicyChecks $checks;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
        $host = $runtime->host;
        $this->cache = new InMemoryReferenceCache($host);
        $this->records = new PdoSelections($host->db, new D\SelectionRecordCodec($host->validator));
        $this->usages = new PdoUsages($host->db, $runtime->tx, $runtime->dependencies);
        $this->checks = new PolicyChecks($host, $host, $host);
    }

    /** @return array{Selections,PdoSelectionCatalog} */
    private function services(): array
    {
        $r = $this->runtime;
        $canonical = new CanonicalChunkSets(array_values($r->text->sets));
        $catalog = new PdoSelectionCatalog($r->host->db, $canonical, $r->host->validator,
            $r->text->documents, new CanonicalUnits());
        $index = new PdoLexicalIndex($r->host->db, $r->tx, $r->host->validator, $canonical, $r->host);
        $selections = new Selections($this->checks, $index, $catalog, $this->records,
            new BoundedSelector(), $r->host, $r->host, $r->host->validator, $this->cache);

        return [$selections, $catalog];
    }

    public function search(Command $command): Response
    {
        $r = $this->runtime;
        $scope = $r->host->scope();
        // Resolve the active pointer exactly once, then carry the immutable snapshot throughout.
        $snapshot = $r->tx->run(function () use ($r, $scope): ?D\PublicationSnapshot {
            $r->tx->lockTenant($scope->tenantId, D\LockMode::Share);
            $r->tx->lockKb($scope->kbId, D\LockMode::Share);

            return $r->catalog->resolveSnapshot($scope->tenantId, $scope->kbId,
                D\SnapshotSelector::active(), new D\ProfileId(PdoLexicalIndex::PROFILE));
        });
        if ($snapshot === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'publication_unavailable');
        }
        $query = (new Normalizer())->bind(new D\Query($command->text('query')),
            new D\EffectiveProfile('local-text', '1', ['lexical' => new D\AdapterId('postgres.fts')]),
            D\SelectionLimits::profile($command->input->get('profile') ?? 'compact'));
        [$service] = $this->services();
        $outcome = $service->retrieve($r->host->context(), $query, $snapshot->ref,
            $snapshot->generationIds[0], $r->host->eligible(), $r->host->binding());
        $data = $outcome->result->data;
        $wire = $data instanceof D\Selection ? new JsonObject(['selection' => $data->toWire(),
            'token' => $outcome->token->reveal(), 'readRequirements' => array_map(
                static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $outcome->readRequirements)]) : null;

        return Response::result($outcome->result, $wire);
    }

    public function disclose(Command $command): Response
    {
        $r = $this->runtime;
        $host = $r->host;
        $token = new D\SelectionToken($command->text('token'));
        $record = $this->records->get($host->scope(), $token->hash())
            ?? throw new D\DomainFailure(D\Outcome::Denied, 'selection_invalid');
        $request = new D\DisclosureRequest($command->input->without('token'), $host->validator);
        [$selections, $catalog] = $this->services();
        $host->bindingOverride = $host->binding(array_map(
            static fn (JsonObject $source): string => $source->get('sourceId'), $record->selection->policyBinding()->get('sources')));
        $canonical = new CanonicalBuffer($catalog,
            new PdoCanonicalDocuments($host->db, $r->storage->blobs, $host->validator), $host);
        $service = new Disclosures($selections, $this->checks, $canonical, $this->usages, $host, $host->validator);
        $outcome = $service->disclose($host->context(), $token, $record->query, $record->snapshot, $request);
        $data = $outcome->result->data;
        $wire = $data instanceof D\EvidenceSet ? $data->toWire() : $this->replay($data);

        return Response::result($outcome->result, $wire);
    }

    public function read(Command $command): Response
    {
        $r = $this->runtime;
        $host = $r->host;
        $store = new PdoReadingStore($host->db, $r->tx, $this->usages, $r->dependencies, $host->validator, $host);
        $citation = $store->citation($host->scope(), $command->id('citationId'));
        $host->bindingOverride = $store->plan($host->scope(), $citation)->binding();
        $request = new R\ReadRequest($command->id('citationId'), $command->id('attemptId'), $this->target($command),
            D\SelectionLimits::profile('compact'), 1048576, $host->now()->plusSeconds(30));
        $canonical = new CanonicalReads(new PdoCanonicalDocuments($host->db, $r->storage->blobs, $host->validator),
            $store, new BoundedObjects($r->storage->blobs));
        $result = (new Readings($store, $this->checks, $canonical, $host))->read($host->context(), $request);
        $data = $result->data;
        $wire = $data instanceof R\ReadResponse ? new JsonObject([
            'citation' => $data->citation->toWire(), 'usageRef' => $data->usageRef->value,
            'checkedAt' => $data->checkedAt->value, 'contentBase64' => base64_encode($data->body->bytes()),
            'mediaType' => $data->body->mediaType, 'metadata' => $data->body->metadata(),
        ]) : $this->replay($data);

        return Response::result($result, $wire);
    }

    private function target(Command $command): R\ReadTarget
    {
        return match ($command->name) {
            'read source' => R\ReadTarget::source(),
            'read page' => R\ReadTarget::page(new D\LocalId($command->text('pageId'))),
            'read region' => R\ReadTarget::region(new D\LocalId($command->text('pageId')), $this->bbox($command->input->get('bbox'))),
            'read table' => R\ReadTarget::table(new D\LocalId($command->text('tableId'))),
            'read asset' => R\ReadTarget::asset(new D\LocalId($command->text('assetId'))),
            default => throw new \KnowKit\Adapter\Cli\UsageError(),
        };
    }

    private function bbox(JsonObject $wire): D\Bbox
    {
        if (count($wire) !== 4) {
            throw new \KnowKit\Adapter\Cli\UsageError();
        }

        return new D\Bbox($wire->get('x0'), $wire->get('y0'), $wire->get('x1'), $wire->get('y1'));
    }

    private function replay(?object $data): ?JsonObject
    {
        if (!$data instanceof D\DisclosureReplay) {
            return null;
        }

        return new JsonObject(['replay' => true, 'usageRef' => $data->usageRef->value,
            'checkedAt' => $data->checkedAt->value, 'evidenceDigest' => $data->evidenceDigest->value]);
    }
}
