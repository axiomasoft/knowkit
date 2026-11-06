<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Catalog;
use KnowKit\Domain\Fingerprint;

/** The owning chunker supplies its validated manifest identity; persistence does not invent a second manifest format. */
final readonly class ChunkSetMetadata
{
    /** @param array<string,ChunkMetadata> $chunks exact chunk IDs */
    public function __construct(public Fingerprint $manifestDigest,public array $chunks) {}
}
