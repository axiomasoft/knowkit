<?php

declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SelectedReferences
{
    /**
     * @param list<ChunkRef> $refs
     * @param list<ChunkRef> $readRequirements
     * @param list<string> $warnings
     */
    public function __construct(
        public array $refs,
        public array $readRequirements,
        public array $warnings,
    ) {}
}
