<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Immutable exact original bytes. Debug output deliberately contains no bytes. */
final readonly class SourceBytes
{
    public function __construct(
        public SourceVersionRef $ref,
        #[\SensitiveParameter] private string $bytes,
        public string $mediaType,
    ) {
        if (strlen($bytes) > 67108864) {
            throw new DomainFailure(Outcome::Incompatible, 'input_limit');
        }
        $ref->assertBytes($bytes);
        ValueGuard::text(value: $mediaType, max: 128);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function byteLength(): int
    {
        return strlen($this->bytes);
    }

    public function __debugInfo(): array
    {
        return ['ref' => $this->ref, 'byteLength' => $this->byteLength()];
    }
}
