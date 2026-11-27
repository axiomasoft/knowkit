<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Application\Retrieval\Pruning\SubspanProjector;
use KnowKit\Domain as D;
use KnowKit\Domain\Artifacts\DerivedArtifactRef;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Pruning\AtomicByteRange;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Port\BlobStore;
use KnowKit\Port\CanonicalDocuments;
use KnowKit\Port\ContextPruner;
use KnowKit\Port\IdGenerator;
use KnowKit\Port\ResolutionStore;
use KnowKit\Port\SelectionCatalog;
use KnowKit\Port\Tokenizer;

/** Fresh object reads and span checks happen before BEGIN; no backend snippets are accepted. */
final readonly class CanonicalBuffer
{
    private const MAX_PRELOAD_BYTES = 8388608;

    public function __construct(
        private SelectionCatalog $catalog,
        private CanonicalDocuments $documents,
        private IdGenerator $ids,
        private ?Tokenizer $tokenizer = null,
        private ?ResolutionStore $resolutions = null,
        private ?BlobStore $blobs = null,
        private ?ContextPruner $pruner = null,
    ) {}

    public function preload(
        D\SelectionRecord $record,
        D\DisclosureRequest $request,
        ?DisclosureMode $maxDisclosureMode = null,
    ): D\DisclosureBuffer {
        $tier = $request->resolutionTier();
        if ($maxDisclosureMode !== null && !$maxDisclosureMode->allowsTier($tier)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'verbatim_disclosure_forbidden');
        }

        $refs = $request->refs($record);
        $inventory = $this->catalog->load($record->context->scope, $record->snapshot, $record->selection->generationId());
        if (!$inventory->snapshot->equals($record->snapshot) || !$inventory->generationId()->equals($record->selection->generationId())) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_mismatch');
        }

        if ($tier !== ResolutionTier::R3Verbatim) {
            return $this->preloadDerived($record, $inventory, $refs, $tier);
        }

        $documents = $fragments = $maps = [];
        $remaining = self::MAX_PRELOAD_BYTES;
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

        $policyWire = $request->pruningPolicy();
        if ($policyWire !== null && $this->pruner !== null) {
            return $this->pruneVerbatim($record, $refs, $units, $sets, $documents, $maps, PruningPolicy::fromWire($policyWire));
        }

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
    }

    /**
     * @param list<D\ChunkRef> $refs
     * @param list<D\SelectionUnit> $units
     * @param array<string, D\ChunkingResult> $sets
     * @param array<string, D\IrDocument> $documents
     * @param array<string, CanonicalMap> $maps
     */
    private function pruneVerbatim(
        D\SelectionRecord $record,
        array $refs,
        array $units,
        array $sets,
        array $documents,
        array &$maps,
        PruningPolicy $policy,
    ): D\DisclosureBuffer {
        $prunableUnits = [];
        $atomMap = [];
        foreach ($units as $index => $unit) {
            $chunk = $unit->chunk;
            $doc = $documents[$chunk->ref->revisionRef->irDigest->value];
            $result = $sets[$chunk->ref->chunkSetId->value];
            $meta = $result->metadata($chunk->ref->chunkId->value);
            $map = $this->canonicalMap($chunk, $doc, $maps);
            $atoms = $this->atomicRangesForChunk($chunk, $doc, $map, $meta->get('joiner'));
            $atomMap[$index] = $atoms;
            $prunableUnits[] = new PrunableUnit($chunk, $atoms);
        }

        $selections = $this->pruner?->prune($record->query, $prunableUnits, $policy) ?? [];
        if (count($selections) !== count($units)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_pruned_subspan');
        }

        $projector = new SubspanProjector();
        $fragments = [];
        $bytes = 0;
        foreach ($units as $index => $unit) {
            $chunk = $unit->chunk;
            $doc = $documents[$chunk->ref->revisionRef->irDigest->value];
            $result = $sets[$chunk->ref->chunkSetId->value];
            $meta = $result->metadata($chunk->ref->chunkId->value);
            $map = $this->canonicalMap($chunk, $doc, $maps);
            /** @var PrunedSelection $selection */
            $selection = $selections[$index];
            $fragment = $projector->project(
                $chunk,
                $doc,
                $atomMap[$index],
                $selection,
                $map,
                ' … ',
                false,
                $policy->maxKeptBytes,
                $meta->get('joiner'),
                $meta,
                $this->ids->next(),
            );
            $fragments[] = $fragment;
            $size = strlen((string) $fragment->get('text'));
            $bytes += $size;
            if ($size > $record->query->limits->maxBytesPerFragment || $bytes > $record->query->limits->maxBytes) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
        }

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
    }

    /**
     * @param array<string, CanonicalMap> $maps
     */
    private function canonicalMap(D\Chunk $chunk, D\IrDocument $document, array &$maps): CanonicalMap
    {
        $ref = $chunk->ref;
        $source = $ref->revisionRef->sourceRef;
        $parser = $ref->revisionRef->parserSignature;
        $mapKey = implode("\0", [
            $source->tenantId->value, $source->sourceId->value, $source->sourceVersionId->value, $source->sourceDigest->value,
            $ref->revisionRef->documentRevisionId->value, $ref->revisionRef->irDigest->value,
            $parser->name, $parser->version, $parser->profileDigest->value, $chunk->documentId->value,
        ]);

        return $maps[$mapKey] ??= new CanonicalMap($document, $ref->revisionRef, $chunk->documentId);
    }

    /**
     * @return list<AtomicByteRange>
     */
    private function atomicRangesForChunk(
        D\Chunk $chunk,
        D\IrDocument $document,
        CanonicalMap $map,
        ?string $chunkJoiner,
    ): array {
        $atomicBlocks = [];
        foreach ($document->toWire()->get('blocks') as $block) {
            if (!$block instanceof JsonObject) {
                continue;
            }
            $kind = (string) $block->get('kind');
            $isAtomic = in_array($kind, ['formula', 'code', 'table'], true);
            if (!$isAtomic && $block->has('metadata')) {
                $meta = $block->get('metadata');
                if ($meta instanceof JsonObject && $meta->has('atomic') && $meta->get('atomic') === true) {
                    $isAtomic = true;
                    $kind = 'atomic';
                }
            }
            if ($isAtomic) {
                $atomicBlocks[] = ['kind' => $kind, 'spans' => $block->get('spans')];
            }
        }
        if ($atomicBlocks === []) {
            return [];
        }

        $ranges = [];
        $offset = 0;
        $joinerLen = $chunkJoiner === null ? 0 : strlen($chunkJoiner);
        foreach ($chunk->spans as $spanIndex => $span) {
            $wire = $span->locator->toWire();
            $unitKeyName = $wire->get('kind') === 'page' ? 'pageId' : 'segmentId';
            $unitId = $wire->get($unitKeyName);
            $spanStart = (int) $wire->get('byteStart');
            $spanEnd = (int) $wire->get('byteEnd');
            $partLen = strlen($map->assembler->assemble([$span], null));

            foreach ($atomicBlocks as $block) {
                foreach ($block['spans'] as $bSpan) {
                    if (!$bSpan instanceof JsonObject) {
                        continue;
                    }
                    if ($bSpan->get('kind') !== $wire->get('kind') || $bSpan->get($unitKeyName) !== $unitId) {
                        continue;
                    }
                    $ovStart = max($spanStart, (int) $bSpan->get('byteStart'));
                    $ovEnd = min($spanEnd, (int) $bSpan->get('byteEnd'));
                    if ($ovStart < $ovEnd) {
                        $ranges[] = new AtomicByteRange(
                            $offset + ($ovStart - $spanStart),
                            $offset + ($ovEnd - $spanStart),
                            $block['kind'],
                        );
                    }
                }
            }

            $offset += $partLen + ($spanIndex < count($chunk->spans) - 1 ? $joinerLen : 0);
        }

        return $ranges;
    }

    /**
     * @param list<D\ChunkRef> $refs
     */
    private function preloadDerived(
        D\SelectionRecord $record,
        D\SelectionInventory $inventory,
        array $refs,
        ResolutionTier $tier,
    ): D\DisclosureBuffer {
        if ($this->resolutions === null || $this->blobs === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'resolution_unavailable');
        }

        $sets = $this->sets($inventory);
        $remaining = self::MAX_PRELOAD_BYTES;
        $bytes = 0;
        $fragments = [];
        $units = [];
        $texts = [];
        /** @var array<string, array{artifact: DerivedArtifactRef, text: string}> $loaded */
        $loaded = [];

        foreach ($refs as $ref) {
            $unit = $inventory->unit($ref);
            $units[] = $unit;
            $revisionRef = $ref->revisionRef;
            $tenantId = $revisionRef->sourceRef->tenantId;
            $revisionKey = $revisionRef->documentRevisionId->value;

            if (!isset($loaded[$revisionKey])) {
                $artifact = $this->resolutions->findArtifact($tenantId, $revisionRef->documentRevisionId, $tier);
                if ($artifact === null) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'resolution_unavailable');
                }
                if ($artifact->tier !== $tier) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
                }
                if ($artifact->byteLength < 1 || $artifact->byteLength > $remaining || $artifact->byteLength > 262144) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
                }
                $blobBytes = $this->readVerifiedArtifact($artifact, $tenantId, $revisionRef->sourceRef->sourceId);
                $remaining -= strlen($blobBytes);
                if ($remaining < 0) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
                }
                $loaded[$revisionKey] = ['artifact' => $artifact, 'text' => $blobBytes];
            }

            $artifact = $loaded[$revisionKey]['artifact'];
            $artifactText = $loaded[$revisionKey]['text'];
            $fragments[] = $this->derivedFragment($unit->chunk, $sets, $artifact, $artifactText);
            $texts[] = $artifactText;
            $bytes += strlen($artifactText);
        }

        $this->requireDerivedLimits($units, $texts, $record->query->limits);

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
    }

    private function readVerifiedArtifact(DerivedArtifactRef $artifact, D\TenantId $tenantId, D\SourceId $sourceId): string
    {
        if ($artifact->blobRef !== null) {
            if (!$artifact->blobRef->tenantId->equals($tenantId)
                || !$artifact->blobRef->sourceId->equals($sourceId)
                || !$artifact->blobRef->digest->equals($artifact->blobDigest)
            ) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
            $blobRef = $artifact->blobRef;
        } else {
            $blobRef = new D\BlobRef(
                $tenantId,
                $sourceId,
                $artifact->blobDigest,
                new D\Uint64((string) $artifact->byteLength),
                'resolution-artifacts',
                $artifact->artifactId->value,
                'owned',
            );
        }

        $stream = $this->blobs?->open($blobRef)
            ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'resolution_unavailable');
        $bytes = '';
        $expectedLength = $artifact->byteLength;
        try {
            while (true) {
                $part = $stream->read(min(65536, $expectedLength - strlen($bytes) + 1));
                $ended = $stream->eof();
                $bytes .= $part;
                if (strlen($bytes) > $expectedLength || ($part === '' && !$ended)) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
                }
                if ($ended) {
                    break;
                }
            }
        } finally {
            $stream->close();
        }

        if (strlen($bytes) !== $expectedLength || !hash_equals($artifact->blobDigest->value, hash('sha256', $bytes))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }

        return $bytes;
    }

    /**
     * @param array<string, D\ChunkingResult> $sets
     */
    private function derivedFragment(
        D\Chunk $chunk,
        array $sets,
        DerivedArtifactRef $artifact,
        string $text,
    ): JsonObject {
        $ref = $chunk->ref;
        $result = $sets[$ref->chunkSetId->value]
            ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'candidate_not_in_manifest');
        $meta = $result->metadata($ref->chunkId->value);
        $source = $ref->revisionRef->sourceRef;

        $spans = [];
        foreach (array_slice($chunk->spans, 0, 64) as $span) {
            $spans[] = $span->locator->toWire()->with('textDigest', $span->textDigest->value);
        }

        $locators = $artifact->supportingLocators !== []
            ? $artifact->supportingLocators
            : array_map(static fn (D\Span $span): D\TextLocator => $span->locator, $chunk->spans);
        $supportingRefs = [];
        foreach ($locators as $locator) {
            $blockId = $locator->toWire()->get('blockId');
            if (is_string($blockId) && $blockId !== '') {
                $supportingRefs[$blockId] = true;
            }
        }
        $metaRefs = $meta->get('supportingRefs');
        if (is_array($metaRefs)) {
            foreach ($metaRefs as $metaRef) {
                if (is_string($metaRef) && $metaRef !== '') {
                    $supportingRefs[$metaRef] = true;
                }
            }
        }
        $supportingList = array_slice(array_keys($supportingRefs), 0, 256);

        $citation = new JsonObject([
            'citationId' => $this->ids->next()->value,
            'tenantId' => $source->tenantId->value,
            'sourceId' => $source->sourceId->value,
            'sourceVersionId' => $source->sourceVersionId->value,
            'sourceDigest' => $source->sourceDigest->value,
            'documentRevisionId' => $ref->revisionRef->documentRevisionId->value,
            'documentId' => $chunk->documentId->value,
            'irDigest' => $ref->revisionRef->irDigest->value,
            'contentDigest' => $artifact->blobDigest->value,
            'origin' => $artifact->origin,
            'spans' => $spans,
        ]);
        if ($supportingList !== []) {
            $citation = $citation->with('supportingRefs', $supportingList);
        }

        return new JsonObject([
            'citation' => $citation,
            'text' => $text,
            'joiner' => null,
            'fragmentDigest' => $artifact->blobDigest->value,
            'provenanceKind' => $artifact->provenanceKind,
        ]);
    }

    /**
     * @param list<D\SelectionUnit> $units
     * @param list<string> $texts
     */
    private function requireDerivedLimits(array $units, array $texts, D\SelectionLimits $limits): void
    {
        $bytes = $tokens = 0;
        $assets = [];
        if (count($units) > $limits->maxFragments) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        $checkedTokenizer = false;
        foreach ($units as $index => $unit) {
            $text = $texts[$index];
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
