<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

final readonly class ContextSnapshot
{
    /** @param list<ContextBlock> $blocks */
    public function __construct(
        public array $blocks,
    ) {
    }
}
