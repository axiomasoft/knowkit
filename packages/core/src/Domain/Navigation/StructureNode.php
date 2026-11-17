<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

final readonly class StructureNode
{
    /**
     * @param list<D\Span> $spans
     * @param list<string> $supportingRefs
     */
    public function __construct(
        public D\LocalId $id,
        public string $kind,
        public ?D\LocalId $parentId,
        public int $depth,
        public ?string $title,
        public array $spans,
        public string $origin,
        public string $provenanceKind,
        public array $supportingRefs,
    ) {}
}
