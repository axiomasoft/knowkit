<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use InvalidArgumentException;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;

/** Immutable chunkset and provenance sidecar shared by builders and persistence. */
readonly class ChunkingResult
{
    private const TRANSIENT_MANIFEST_KEYS = [
        'chunkId',
        'chunkDigest',
        'spans',
        'byteLength',
        'ordinal',
        'structurePath',
    ];

    private FrozenJson $manifest;

    public Fingerprint $manifestDigest;

    final public function __construct(
        public ChunkSet $chunkSet,
        JsonObject $manifest,
    ) {
        $this->manifest = new FrozenJson($manifest);
        $this->manifestDigest = new Fingerprint(HashDomain::ChunkSetManifest->digest($manifest));
    }

    final public function manifest(): JsonObject
    {
        return $this->manifest->toWire();
    }

    final public function canonicalManifest(): string
    {
        return $this->manifest->canonical();
    }

    /** @return list<mixed> */
    final public function followups(): array
    {
        return $this->manifest()->get('followups');
    }

    /** @return list<string> */
    final public function expectedChunkIds(): array
    {
        $ids = array_map(
            static fn (Chunk $chunk): string => $chunk->ref->chunkId->value,
            $this->chunkSet->chunks,
        );
        sort($ids, SORT_STRING);

        return $ids;
    }

    final public function metadata(string $chunkId): JsonObject
    {
        foreach ($this->manifest()->get('chunks') as $entry) {
            if ($entry->get('chunkId') === $chunkId) {
                return $entry;
            }
        }

        throw new InvalidArgumentException('Unknown exact chunk');
    }

    /**
     * Build persisted chunk provenance with locators and explicit roles only.
     * Canonical text lives in resource storage, never in a second JSONB text store.
     */
    final public function provenance(string $chunkId): JsonObject
    {
        $entry = $this->metadata($chunkId);
        $context = array_map(
            static fn (JsonObject $item): JsonObject => $item->without('text'),
            $entry->get('context'),
        );

        $provenance = $entry;
        foreach (self::TRANSIENT_MANIFEST_KEYS as $key) {
            $provenance = $provenance->without($key);
        }

        return $provenance->with('context', $context);
    }

    /** @return list<string> */
    final public function structurePath(string $chunkId): array
    {
        return $this->metadata($chunkId)->get('structurePath');
    }

    /** @return array{chunkSetId: ChunkSetId, manifestDigest: Fingerprint} */
    final public function __debugInfo(): array
    {
        return [
            'chunkSetId' => $this->chunkSet->chunkSetId,
            'manifestDigest' => $this->manifestDigest,
        ];
    }
}
