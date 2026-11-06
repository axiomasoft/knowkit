<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class PublicationSnapshot
{
    /** @param list<GenerationId> $generationIds */
    public function __construct(public SnapshotRef $ref, public array $generationIds)
    {
        ValueGuard::uniqueIds($generationIds, GenerationId::class);
    }
}
