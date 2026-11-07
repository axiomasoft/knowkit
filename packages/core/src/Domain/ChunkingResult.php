<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** Immutable chunkset and provenance sidecar shared by builders and persistence. */
readonly class ChunkingResult
{
    private FrozenJson $manifest;
    public Fingerprint $manifestDigest;
    final public function __construct(public ChunkSet $chunkSet, JsonObject $manifest)
    {
        $this->manifest = new FrozenJson($manifest);
        $this->manifestDigest = new Fingerprint(HashDomain::ChunkSetManifest->digest($manifest));
    }
    final public function manifest(): JsonObject { return $this->manifest->toWire(); }
    final public function canonicalManifest(): string { return $this->manifest->canonical(); }
    final public function followups(): array { return $this->manifest()->get('followups'); }
    final public function metadata(string $chunkId): JsonObject
    {
        foreach ($this->manifest()->get('chunks') as $entry) {
            if ($entry->get('chunkId') === $chunkId) { return $entry; }
        }
        throw new \InvalidArgumentException('Unknown exact chunk');
    }
    final public function __debugInfo(): array { return ['chunkSetId' => $this->chunkSet->chunkSetId, 'manifestDigest' => $this->manifestDigest]; }
}
