<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** Extraction confidence in permille, never a semantic correctness score. */
final readonly class FormulaMetadata
{
    /** @param list<JsonObject> $regions */
    private function __construct(
        public ?int $confidence,
        public string $representation,
        public array $regions,
    ) {
    }

    public static function fromDocument(IrDocument $document, LocalId $blockId): self
    {
        $block = $document->block($blockId);
        $confidence = self::confidence($document, $blockId);
        $regions = $block->get('spans');
        if ($block->get('kind') !== 'formula') {
            throw new DomainFailure(Outcome::Incompatible, 'formula_metadata_invalid');
        }
        if ($regions === []) {
            throw new DomainFailure(Outcome::Incompatible, 'formula_region_unavailable');
        }

        $parts = [];
        foreach ($regions as $span) {
            if ($span->get('kind') === 'page' && !$span->has('bbox')) {
                throw new DomainFailure(Outcome::Incompatible, 'formula_region_unavailable');
            }
            $parts[] = self::text($document, $span);
        }

        return new self($confidence, implode("\n", $parts), $regions);
    }

    private static function confidence(IrDocument $document, LocalId $blockId): ?int
    {
        $prefix = 'formula_v1_' . substr(hash('sha256', $blockId->value), 0, 24) . '_conf_';
        $values = [];
        foreach ($document->toWire()->get('coverage')->get('diagnostics') as $diagnostic) {
            if (str_starts_with($diagnostic->get('code'), $prefix)) {
                $values[] = substr($diagnostic->get('code'), strlen($prefix));
            }
        }
        if (count($values) !== 1 || preg_match('/^(unknown|0|[1-9][0-9]{0,3})$/D', $values[0]) !== 1
            || ($values[0] !== 'unknown' && (int) $values[0] > 1000)) {
            throw new DomainFailure(Outcome::Incompatible, 'formula_metadata_invalid');
        }

        return $values[0] === 'unknown' ? null : (int) $values[0];
    }

    private static function text(IrDocument $document, JsonObject $span): string
    {
        $page = $span->get('kind') === 'page';
        $key = $page ? 'pageId' : 'segmentId';
        foreach ($page ? $document->pages() : $document->segments() as $unit) {
            if ($unit->get($key) === $span->get($key)) {
                $text = $unit->get('text');
                $start = $span->get('byteStart');
                $end = $span->get('byteEnd');
                // byteEnd is exclusive. A short or inverted span must not become an empty quote.
                if (!is_string($text) || !is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end > strlen($text)) {
                    throw new DomainFailure(Outcome::Incompatible, 'formula_region_unavailable');
                }

                return substr($text, $start, $end - $start);
            }
        }

        throw new DomainFailure(Outcome::Incompatible, 'formula_region_unavailable');
    }
}
