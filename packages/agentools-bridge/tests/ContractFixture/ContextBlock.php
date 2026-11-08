<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

/** Synthetic surrogate; never defines or aliases an installed owner class. */
final readonly class ContextBlock
{
    public function __construct(
        public string $sourceRef,
        public string $sourceVersion,
        public string $sourceHash,
        public string $irDigest,
        public string $spanRef,
        public string $modelView,
    ) {
        Contract::assertDto(name: 'ContextBlock', value: get_object_vars($this));
    }

    public function modelViewDigest(): string
    {
        return hash('sha256', $this->modelView);
    }
}
