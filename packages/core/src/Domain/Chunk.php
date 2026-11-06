<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Chunk
{
    /** @param list<Span> $spans */
    public function __construct(public ChunkRef $ref, public DocumentId $documentId, public array $spans, private string $text)
    {
        ValueGuard::objects($spans, Span::class, 1024);
        if ($spans === [] || preg_match('//u', $text) !== 1 || !hash_equals($ref->chunkDigest->value, hash('sha256', $text))) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
    }
    public function text(): string { return $this->text; }
    public function __debugInfo(): array { return ['ref'=>$this->ref]; }
}
