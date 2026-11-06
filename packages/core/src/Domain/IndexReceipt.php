<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class IndexReceipt
{
    public function __construct(public TenantId $tenantId, public GenerationId $generationId,
        public ChunkSetId $chunkSetId, public Fingerprint $manifestDigest, public int $indexedCount,
        public ?Fingerprint $embeddingSpaceDigest = null)
    {
        if ($indexedCount < 0 || $indexedCount > 4294967295) { throw new \InvalidArgumentException('Index count required'); }
    }
}
