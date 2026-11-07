<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

final readonly class StructureOutline
{
    /** @param list<StructureNode> $nodes */
    public function __construct(
        public D\Outcome $status,
        public D\DocumentRevisionRef $revision,
        public array $nodes = [],
        public bool $truncated = false,
        public ?string $errorCode = null,
        public ?D\FrozenJson $coverage = null,
    ) {}
}
