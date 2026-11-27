<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain as D;
use KnowKit\Domain\Artifacts\DerivedArtifactRef;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Port\BlobStore;
use KnowKit\Port\CanonicalDocuments;
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

        return new D\DisclosureBuffer($fragments, $refs, $bytes);
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
