<?php

declare(strict_types=1);

namespace KnowKit\Application\Reading;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\CanonicalDocuments;
use KnowKit\Port\PreviewObjects;
use KnowKit\Port\ReadingStore;
use KnowKit\Port\Tokenizer;

/** Exact canonical artifacts only; no fuzzy quote, nearest visual, clipping or latest lookup. */
final readonly class CanonicalReads
{
    public function __construct(
        private CanonicalDocuments $documents,
        private ReadingStore $store,
        private PreviewObjects $objects,
        private ?Tokenizer $tokenizer = null,
    ) {}

    /** @return array{R\ReadBody,?R\BlobObject} */
    public function preload(R\CitationRecord $record, R\ReadRequest $request): array
    {
        $record->requirePayload();
        $document = $this->documents->read($record->chunk->revisionRef, 8388608);
        $map = new CanonicalMap($document, $record->chunk->revisionRef, $record->citation->documentId());
        $quote = $map->assembler->assemble($record->citation->spans(), $record->joiner);
        if (hash('sha256', $quote) !== $record->citation->contentDigest()->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }
        $remaining = 8388608 - strlen($document->canonical());
        if ($remaining < 0 || ($remaining < 1 && !in_array($request->target->kind, ['quote', 'page', 'table'], true))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        [$body, $object] = $this->body($record, $request->target, $document, $map, $quote, min($request->maxBytes, max($remaining, 1)));
        $this->requireLimits($body, $request);

        return [$body, $object];
    }

    /** @return array{R\ReadBody,?R\BlobObject} */
    private function body(R\CitationRecord $record, R\ReadTarget $target, D\IrDocument $document,
        CanonicalMap $map, string $quote, int $maxBytes): array
    {
        if ($target->kind === 'quote') {
            return [new R\ReadBody($quote, 'text/plain', new JsonObject([
                'spans' => $map->wire($record->citation->spans()), 'joiner' => $record->joiner,
                'origin' => $record->citation->toWire()->get('origin'),
                'supportingRefs' => $record->citation->toWire()->get('supportingRefs') ?? [],
            ])), null];
        }
        if ($target->kind === 'page') {
            return [$this->page($document, $target), null];
        }
        if ($target->kind === 'table') {
            return [$this->table($document, $target, $map), null];
        }
        if ($target->kind === 'source') {
            return $this->blob($record, $target, new JsonObject(['target' => $target->toWire()]), $maxBytes);
        }

        return $this->visual($record, $target, $document, $maxBytes);
    }

    private function page(D\IrDocument $document, R\ReadTarget $target): R\ReadBody
    {
        $pages = array_values(array_filter($document->pages(), static fn (JsonObject $page): bool =>
            ($target->id === null || $page->get('pageId') === $target->id->value)
            && ($target->physicalNo === null || $page->get('physicalNo') === $target->physicalNo)
            && ($target->printedLabel === null || $page->get('printedLabel') === $target->printedLabel)));
        if (count($pages) !== 1) {
            return $this->alternatives($pages, 'pageId');
        }
        $page = $pages[0];
        $text = $page->get('text');
        if (hash('sha256', $text) !== $page->get('textDigest')) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }
        if (!in_array($page->get('status'), ['ok', 'empty'], true)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'page_unavailable');
        }

        $metadata = $page->without('text')->with('coverage', $document->toWire()->get('coverage'))
            ->with('sourceUnits', [new JsonObject(['kind' => 'page', 'id' => $page->get('pageId')])]);

        return new R\ReadBody($text, 'text/plain', $metadata);
    }

    private function table(D\IrDocument $document, R\ReadTarget $target, CanonicalMap $map): R\ReadBody
    {
        $targetId = $target->id ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_target');
        $block = $document->block($targetId);
        if ($block->get('kind') !== 'table') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'table_required');
        }
        $cells = $units = [];
        foreach ($block->get('table')->get('cells') as $cell) {
            $spans = $map->spans($cell->get('spans'));
            $cells[] = $cell->with('text', $spans === [] ? '' : $map->text($spans))
                ->with('spans', $map->wire($spans));
            foreach ($spans as $span) {
                $locator = $span->locator->toWire();
                $kind = $locator->get('kind');
                $id = $locator->get($kind === 'page' ? 'pageId' : 'segmentId');
                $units[$kind . ':' . $id] = new JsonObject(['kind' => $kind, 'id' => $id]);
            }
        }
        $table = $block->get('table')->with('cells', $cells);
        $metadata = new JsonObject(['blockId' => $targetId->value, 'origin' => $block->get('origin'),
            'provenance' => $block->get('provenance'), 'completeRows' => true, 'omittedRows' => 0,
            'sourceUnits' => array_values($units)]);

        return new R\ReadBody(Jcs::canonicalize($table), 'application/json', $metadata);
    }

    /** @return array{R\ReadBody,?R\BlobObject} */
    private function visual(R\CitationRecord $record, R\ReadTarget $target, D\IrDocument $document, int $maxBytes): array
    {
        $targetId = $target->id ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_target');
        $assets = array_values(array_filter($document->assets(), static fn (JsonObject $asset): bool =>
            $target->kind === 'asset' ? $asset->get('assetId') === $targetId->value
                : ($asset->get('pageId') === $targetId->value && Jcs::canonicalize($asset->get('bbox')) === Jcs::canonicalize($target->region?->toWire()))));
        if (count($assets) !== 1) {
            return [$this->alternatives($assets, 'assetId'), null];
        }
        $asset = $assets[0];
        $exact = R\ReadTarget::asset(new D\LocalId($asset->get('assetId')));
        [$body, $object] = $this->blob($record, $exact, $asset->with('transform', new JsonObject([
            'kind' => 'retained-ir-asset', 'sourceBbox' => $asset->get('bbox'),
            'coordinateSpace' => 'page-millionths-top-left-v1',
        ])), $maxBytes);
        if ($object->blob->digest->value !== $asset->get('digest')
            || $object->blob->byteLength->value !== $asset->get('byteLength') || $object->mediaType !== $asset->get('mediaType')) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }

        return [$body, $object];
    }

    /** @return array{R\ReadBody,R\BlobObject} */
    private function blob(R\CitationRecord $record, R\ReadTarget $target, JsonObject $metadata, int $maxBytes): array
    {
        $object = $this->store->object($record, $target);
        $source = $record->chunk->revisionRef->sourceRef;
        if (!$source->tenantId->equals($object->blob->tenantId) || !$source->sourceId->equals($object->blob->sourceId)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $bytes = $this->objects->read($object, $maxBytes);
        if ($target->kind === 'source') {
            $source->assertBytes($bytes);
        }

        return [new R\ReadBody($bytes, $object->mediaType, $metadata, assets: $target->kind === 'asset' ? 1 : 0), $object];
    }

    /** @param list<JsonObject> $matches */
    private function alternatives(array $matches, string $key): R\ReadBody
    {
        if ($matches === []) {
            throw new D\DomainFailure(D\Outcome::Unknown, 'exact_target_missing');
        }
        $alternatives = array_map(static fn (JsonObject $item): JsonObject => new JsonObject([
            $key => $item->get($key), 'physicalNo' => $item->get('physicalNo'),
            'printedLabel' => $item->get('printedLabel'), 'digest' => $item->get('digest') ?? $item->get('textDigest'),
        ]), $matches);

        return new R\ReadBody('', 'application/json', new JsonObject([
            'resolution' => 'alternatives', 'alternatives' => $alternatives,
        ]), fragments: 0);
    }

    private function requireLimits(R\ReadBody $body, R\ReadRequest $request): void
    {
        $limits = $request->limits;
        $bytes = strlen($body->bytes());
        $text = str_starts_with($body->mediaType, 'text/') || $body->mediaType === 'application/json';
        if ($bytes > $request->maxBytes || $body->fragments > $limits->maxFragments || $body->assets > $limits->maxAssets
            || ($text && ($bytes > $limits->maxBytes || $bytes > $limits->maxBytesPerFragment))) {
            throw new D\DomainFailure(D\Outcome::Incompatible,
                $request->target->kind === 'table' ? 'table_read_required' : 'limit_exceeded');
        }
        if ($limits->maxTokens !== null) {
            if ($this->tokenizer === null || $limits->tokenizer === null) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'tokenizer_required');
            }
            $this->tokenizer->capabilities()->requireExact($limits->tokenizer);
            if (!$text) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            $count = $this->tokenizer->count($body->bytes(), $limits->tokenizer);
            if ($count < 0) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
            }
            if ($count > $limits->maxTokens) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
        }
        if ($request->etag !== null && !$request->etag->equals($body->digest)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'etag_mismatch');
        }
    }
}
