<?php
declare(strict_types=1);
namespace KnowKit\Domain;

/** Backend's reloaded effective profile/space and full immutable membership. Not a write receipt. */
final readonly class PersistedIndexRead
{
    /** @param list<ChunkRef> $chunks */
    public function __construct(public ProfileId $profile, public ?Fingerprint $embeddingSpaceDigest, public array $chunks)
    { ValueGuard::objects($chunks, ChunkRef::class, 1000000); }
}
