<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class EmbeddingInput
{
    public function __construct(public ChunkRef $ref, private string $text)
    {
        if (preg_match('//u', $text) !== 1) { throw new \InvalidArgumentException('UTF8 required'); }
    }
    public function text(): string { return $this->text; }
    public function digest(): Sha256 { return new Sha256(hash('sha256', $this->text)); }
    public function __debugInfo(): array { return ['ref' => $this->ref, 'digest' => $this->digest()]; }
}
