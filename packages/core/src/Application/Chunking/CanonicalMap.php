<?php
declare(strict_types=1);
namespace KnowKit\Application\Chunking;

use KnowKit\Application\Validation\SpanAssembler;
use KnowKit\Domain\{Bbox, DocumentId, DocumentRevisionRef, IrDocument, LocalId, Sha256, Span, TextLocator};
use KnowKit\Domain\Contract\JsonObject;

/** Uses the existing canonical span verifier, including full revision and tenant binding. */
final readonly class CanonicalMap
{
    public SpanAssembler $assembler;
    public array $blocks;
    private array $units;

    public function __construct(IrDocument $document, DocumentRevisionRef $revision, DocumentId $documentId)
    {
        $this->assembler = new SpanAssembler($document, $revision, $documentId);
        $ir = $document->toWire(); $units = $blocks = [];
        foreach (['pages' => 'pageId', 'segments' => 'segmentId'] as $list => $key) {
            foreach ($ir->get($list) as $unit) { $units[$key . ':' . $unit->get($key)] = $unit; }
        }
        foreach ($ir->get('blocks') as $block) { $blocks[$block->get('blockId')] = $block; }
        $this->blocks = $blocks; $this->units = $units;
    }

    public function span(JsonObject $locator): Span
    {
        $key = $locator->get('kind') === 'page' ? 'pageId' : 'segmentId';
        $unit = $this->units[$key . ':' . $locator->get($key)];
        $box = $locator->get('bbox');
        $bbox = $box === null ? null : new Bbox($box->get('x0'), $box->get('y0'), $box->get('x1'), $box->get('y1'));
        $typed = $key === 'pageId' ? TextLocator::page(new LocalId($locator->get('pageId')), $locator->get('physicalNo'), $locator->get('printedLabel'),
            new LocalId($locator->get('blockId')), $locator->get('byteStart'), $locator->get('byteEnd'), $bbox)
            : TextLocator::segment(new LocalId($locator->get('segmentId')), new LocalId($locator->get('blockId')), $locator->get('byteStart'), $locator->get('byteEnd'));
        return new Span($typed, new Sha256($unit->get('textDigest')));
    }

    public function spans(array $locators): array { return array_map($this->span(...), $locators); }
    public function text(array $spans): string { return $this->assembler->assemble($spans, count($spans) > 1 ? "\n" : null); }
    public function wire(array $spans): array { return array_map(static fn(Span $s): JsonObject => $s->locator->toWire()->with('textDigest', $s->textDigest->value), $spans); }

    public function path(JsonObject $block): array
    {
        $path = [$block->get('blockId')];
        while (($parent = $block->get('parentId')) !== null) {
            array_unshift($path, $parent); $block = $this->blocks[$parent];
        }
        return $path;
    }

    /** Inherited headings are search context with their own refs, never added to quote spans. */
    public function headings(JsonObject $block): array
    {
        $context = [];
        foreach (array_slice($this->path($block), 0, -1) as $id) {
            $parent = $this->blocks[$id];
            if ($parent->get('kind') === 'heading' && $parent->get('spans') !== []) {
                $context[] = $this->context('inherited_heading', $this->spans($parent->get('spans')));
            }
        }
        return $context;
    }

    public function context(string $role, array $spans): JsonObject
    {
        $block = $this->blocks[$spans[0]->locator->toWire()->get('blockId')];
        return new JsonObject(['role' => $role, 'origin' => $block->get('origin'), 'provenance' => $block->get('provenance'), 'isQuote' => false,
            'spans' => $this->wire($spans), 'joiner' => count($spans) > 1 ? "\n" : null, 'text' => $this->text($spans)]);
    }
}
