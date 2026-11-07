<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Exact text, with explicit separators between discontinuous spans. Empty units have no spans. */
final readonly class Unit
{
    /** @param list<D\Span> $spans @param list<SourceUnitRef> $sourceUnits */
    public function __construct(
        public string $kind,
        public D\LocalId $id,
        public ?int $physicalNo,
        public ?string $printedLabel,
        public string $status,
        private string $text,
        public array $spans,
        public array $sourceUnits,
        public ?string $joiner = null,
        public ?string $origin = null,
        public ?string $provenanceKind = null,
        public array $supportingRefs = [],
    ) {
        D\ValueGuard::objects($spans, D\Span::class);
        D\ValueGuard::objects($sourceUnits, SourceUnitRef::class);
        if (!in_array($kind, ['page', 'segment', 'block'], true)
            || !in_array($status, ['ok', 'empty'], true) || preg_match('//u', $text) !== 1
            || strlen($text) > 8192 || count($spans) > 1024 || count($sourceUnits) > 1024
            || ($status === 'empty' && $text !== '')
            || ($kind === 'page' && ($physicalNo === null || $physicalNo < 1 || $physicalNo > 4294967295))
            || ($kind !== 'page' && ($physicalNo !== null || $printedLabel !== null))) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_unit');
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    public function reference(): JsonObject
    {
        return new JsonObject([
            'kind' => $this->kind,
            'id' => $this->id->value,
            'physicalNo' => $this->physicalNo,
            'printedLabel' => $this->printedLabel,
            'status' => $this->status,
            'spans' => array_map(static fn (D\Span $span): JsonObject =>
                $span->locator->toWire()->with('textDigest', $span->textDigest->value), $this->spans),
            'joiner' => $this->joiner,
            'sourceUnits' => array_map(static fn (SourceUnitRef $ref): JsonObject => $ref->toWire(), $this->sourceUnits),
            'fragmentDigest' => hash('sha256', $this->text),
            'byteLength' => strlen($this->text),
            'origin' => $this->origin,
            'provenanceKind' => $this->provenanceKind,
            'supportingRefs' => $this->supportingRefs,
        ]);
    }

    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id];
    }
}
