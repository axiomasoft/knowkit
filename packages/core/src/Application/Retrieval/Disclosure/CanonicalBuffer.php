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
        $documents = $fragments = $maps = [];
        $remaining = 8388608;
        $bytes = 0;
        $units = [];
        $sets = $this->sets($inventory);
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
            if ($canonical !== $unit->chunk->text()) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
            }
            $remaining -= strlen($canonical);
            if ($remaining < 0) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
            }
            $units[] = $unit;
            $fragments[] = $this->fragment($unit->chunk, $sets, $documents[$key], $maps);
            $bytes += strlen($unit->chunk->text());
        }
        $this->requireLimits($units, $record->query->limits);

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
    }

    /**
     * @param array<string, D\ChunkingResult> $sets
     * @param array<string, CanonicalMap> $maps
     */
    private function fragment(D\Chunk $chunk, array $sets, D\IrDocument $document, array &$maps): JsonObject
    {
        $ref = $chunk->ref;
        $result = $sets[$ref->chunkSetId->value] ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'candidate_not_in_manifest');
        $meta = $result->metadata($ref->chunkId->value);
        $source = $ref->revisionRef->sourceRef;
        $parser = $ref->revisionRef->parserSignature;
        $mapKey = implode("\0", [
            $source->tenantId->value, $source->sourceId->value, $source->sourceVersionId->value, $source->sourceDigest->value,
            $ref->revisionRef->documentRevisionId->value, $ref->revisionRef->irDigest->value,
            $parser->name, $parser->version, $parser->profileDigest->value, $chunk->documentId->value,
        ]);
        $map = $maps[$mapKey] ??= new CanonicalMap($document, $ref->revisionRef, $chunk->documentId);
        $map->assembler->assertChunk($chunk, $meta->get('joiner'));
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

    /** @return array<string, D\ChunkingResult> */
    private function sets(D\SelectionInventory $inventory): array
    {
        $sets = [];
        foreach ($inventory->manifest->sets as $set) {
            $sets[$set->chunkSet->chunkSetId->value] ??= $set;
        }

        return $sets;
    }

    /** @param list<D\SelectionUnit> $units */
    private function requireLimits(array $units, D\SelectionLimits $limits): void
    {
        $bytes = $tokens = 0;
        $assets = [];
        if (count($units) > $limits->maxFragments) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        $checkedTokenizer = false;
        foreach ($units as $unit) {
            $text = $unit->budgetText();
            $size = strlen($text);
            $bytes += $size;
            foreach ($unit->assetKeys as $key) {
                $assets[$key] = true;
            }
            if ($size > $limits->maxBytesPerFragment || $bytes > $limits->maxBytes || count($assets) > $limits->maxAssets) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            if ($limits->maxTokens !== null) {
                if ($this->tokenizer === null || $limits->tokenizer === null) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'tokenizer_required');
                }
                if (!$checkedTokenizer) {
                    $this->tokenizer->capabilities()->requireExact($limits->tokenizer);
                    $checkedTokenizer = true;
                }
                $count = $this->tokenizer->count($text, $limits->tokenizer);
                if ($count < 0) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
                }
                if ($count > $limits->maxTokens - $tokens) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
                }
                $tokens += $count;
            }
        }
    }
}
