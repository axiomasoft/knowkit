<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

final readonly class CanonicalBlock
{
    /** @param list<D\Span> $spans */
    public function __construct(
        public D\LocalId $id,
        public string $kind,
        public ?D\LocalId $parentId,
        public int $order,
        public array $spans,
        public string $origin,
        public string $provenanceKind,
        public array $supportingRefs,
    ) {}

    public function contains(CanonicalUnit $unit, int $start, int $end): ?D\TextLocator
    {
        foreach ($this->spans as $span) {
            $wire = $span->locator->toWire();
            $id = $wire->get($unit->ref->kind === 'page' ? 'pageId' : 'segmentId');
            if ($wire->get('kind') === $unit->ref->kind && $id === $unit->ref->id->value
                && $wire->get('byteStart') <= $start && $wire->get('byteEnd') >= $end) {
                return $unit->ref->kind === 'page'
                    ? D\TextLocator::page($unit->ref->id, $unit->physicalNo, $unit->printedLabel, $this->id, $start, $end)
                    : D\TextLocator::segment($unit->ref->id, $this->id, $start, $end);
            }
        }

        return null;
    }
}
