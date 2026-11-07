<?php
declare(strict_types=1);
namespace KnowKit\Application\Chunking;

use KnowKit\Domain\{ChunkSet, Fingerprint, FrozenJson};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** Application sidecar; existing Chunk/ChunkSet and citation wire contracts stay unchanged. */
final readonly class ChunkingResult
{
    private FrozenJson $manifest;
    public Fingerprint $manifestDigest;
    public function __construct(public ChunkSet $chunkSet, JsonObject $manifest)
    {
        $this->manifest = new FrozenJson($manifest);
        $this->manifestDigest = new Fingerprint(HashDomain::ChunkSetManifest->digest($manifest));
    }
    public function manifest(): JsonObject { return $this->manifest->toWire(); }
    public function canonicalManifest(): string { return $this->manifest->canonical(); }
    public function followups(): array { return $this->manifest()->get('followups'); }
    public function metadata(string $chunkId): JsonObject
    {
        foreach ($this->manifest()->get('chunks') as $entry) {
            if ($entry->get('chunkId') === $chunkId) { return $entry; }
        }
        throw new \InvalidArgumentException('Unknown exact chunk');
    }
    public function __debugInfo(): array { return ['chunkSetId' => $this->chunkSet->chunkSetId, 'manifestDigest' => $this->manifestDigest]; }
}
