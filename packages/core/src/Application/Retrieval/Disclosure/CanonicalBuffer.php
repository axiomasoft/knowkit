<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\CanonicalDocuments;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\SelectionCatalog;
use KnowKit\Port\Tokenizer;

/** Fresh object reads and span checks happen before BEGIN; no backend snippets are accepted. */
final readonly class CanonicalBuffer
{
    public function __construct(
        private SelectionCatalog $catalog,
        private CanonicalDocuments $documents,
        private IdGenerator $ids,
        private ?Tokenizer $tokenizer = null,
    ) {}

    public function preload(D\SelectionRecord $record, D\DisclosureRequest $request): D\DisclosureBuffer
    {
        $refs = $request->refs($record);
        $inventory = $this->catalog->load($record->context->scope, $record->snapshot, $record->selection->generationId());
        if (!$inventory->snapshot->equals($record->snapshot) || !$inventory->generationId()->equals($record->selection->generationId())) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_mismatch');
        }
        $documents = $fragments = [];
        $remaining = 8388608;
        $bytes = 0;
        $units = [];
        foreach ($refs as $ref) {
            $key = $ref->revisionRef->irDigest->value;
            if (!isset($documents[$key])) {
                $documents[$key] = $this->documents->read($ref->revisionRef, $remaining);
                $remaining -= strlen($documents[$key]->canonical());
                if ($remaining < 0) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
                }
            }
            $unit = $inventory->unit($ref);
            $canonical = $this->documents->readChunk($ref, $remaining);
            $remaining -= strlen($canonical);
            if ($canonical !== $unit->chunk->text() || $remaining < 0) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
            }
            $units[] = $unit;
            $fragments[] = $this->fragment($unit->chunk, $inventory, $documents[$key]);
            $bytes += strlen($unit->chunk->text());
        }
        $this->requireLimits($units, $record->query->limits);

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
    }

    private function fragment(D\Chunk $chunk, D\SelectionInventory $inventory, D\IrDocument $document): JsonObject
    {
        $ref = $chunk->ref;
        $result = $this->chunkSet($inventory, $ref);
        $meta = $result->metadata($ref->chunkId->value);
        $map = new CanonicalMap($document, $ref->revisionRef, $chunk->documentId);
        $map->assembler->assertChunk($chunk, $meta->get('joiner'));
        $source = $ref->revisionRef->sourceRef;
        $citation = new JsonObject([
            'citationId' => $this->ids->next()->value,
            'tenantId' => $source->tenantId->value,
            'sourceId' => $source->sourceId->value,
            'sourceVersionId' => $source->sourceVersionId->value,
            'sourceDigest' => $source->sourceDigest->value,
            'documentRevisionId' => $ref->revisionRef->documentRevisionId->value,
            'documentId' => $chunk->documentId->value,
            'irDigest' => $ref->revisionRef->irDigest->value,
            'contentDigest' => $ref->chunkDigest->value,
            'origin' => $meta->get('origin'),
            'spans' => $map->wire($chunk->spans),
        ]);
        if ($meta->get('supportingRefs') !== []) {
            $citation = $citation->with('supportingRefs', $meta->get('supportingRefs'));
        }

        return new JsonObject(['citation' => $citation, 'text' => $chunk->text(),
            'joiner' => $meta->get('joiner'), 'fragmentDigest' => $ref->chunkDigest->value,
            'provenanceKind' => $meta->get('kind')]);
    }

    private function chunkSet(D\SelectionInventory $inventory, D\ChunkRef $ref): D\ChunkingResult
    {
        foreach ($inventory->manifest->sets as $set) {
            if ($set->chunkSet->chunkSetId->equals($ref->chunkSetId)) {
                return $set;
            }
        }

        throw new D\DomainFailure(D\Outcome::Incompatible, 'candidate_not_in_manifest');
    }

    /** @param list<D\SelectionUnit> $units */
    private function requireLimits(array $units, D\SelectionLimits $limits): void
    {
        $bytes = $tokens = 0;
        $assets = [];
        if (count($units) > $limits->maxFragments) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        foreach ($units as $unit) {
            $size = strlen($unit->budgetText());
            $bytes += $size;
            $assets = array_unique([...$assets, ...$unit->assetKeys]);
            if ($size > $limits->maxBytesPerFragment || $bytes > $limits->maxBytes || count($assets) > $limits->maxAssets) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            if ($limits->maxTokens !== null) {
                if ($this->tokenizer === null || $limits->tokenizer === null) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'tokenizer_required');
                }
                $this->tokenizer->capabilities()->requireExact($limits->tokenizer);
                $tokens += $this->tokenizer->count($unit->budgetText(), $limits->tokenizer);
                if ($tokens > $limits->maxTokens) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
                }
            }
        }
    }
}
