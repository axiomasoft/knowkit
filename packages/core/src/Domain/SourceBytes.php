<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Immutable exact original bytes. Debug output deliberately contains no bytes. */
final readonly class SourceBytes
{
    public function __construct(public SourceVersionRef $ref, private string $bytes, public string $mediaType)
    {
        $ref->assertBytes($bytes); ValueGuard::text($mediaType, 128);
    }
    public function bytes(): string { return $this->bytes; }
    public function byteLength(): int { return strlen($this->bytes); }
    public function __debugInfo(): array { return ['ref' => $this->ref, 'byteLength' => $this->byteLength()]; }
}
