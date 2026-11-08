<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

/** Input text is untrusted; scope is exclusively the real ExecutionContext. */
final readonly class IncomingInput
{
    public function __construct(
        public string $text,
    ) {
    }
}
