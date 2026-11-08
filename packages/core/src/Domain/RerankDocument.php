<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Canonical whole chunk; the caller applies SelectionLimits before constructing the payload. */
final readonly class RerankDocument
{
    public function __construct(
        public ChunkRef $ref,
        private string $text,
    ) {
        if (preg_match('//u', $text) !== 1 || hash('sha256', $text) !== $ref->chunkDigest->value) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    public function __debugInfo(): array
    {
        return ['ref' => $this->ref];
    }
}
