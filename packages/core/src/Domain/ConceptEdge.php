<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** Directed concept graph edge bound to a publication snapshot. */
final readonly class ConceptEdge
{
    /** @var list<string> */
    public const RELATIONS = [
        'prerequisite_of',
        'defines',
        'proves',
        'exemplifies',
        'refines',
        'contradicts',
        'cites',
        'applies_to',
    ];

    /** @var list<string> */
    public const ORIGINS = ['source', 'ocr', 'generated', 'deterministic'];

    public function __construct(
        public ChunkId $fromChunkId,
        public ChunkId $toChunkId,
        public string $relation,
        public int $weightBps,
        public string $origin,
    ) {
        if ($fromChunkId->equals($toChunkId)) {
            throw new \InvalidArgumentException('Self-loop concept edge forbidden');
        }
        if (!in_array($relation, self::RELATIONS, true)) {
            throw new \InvalidArgumentException('Invalid concept edge relation');
        }
        if ($weightBps < 1 || $weightBps > 10000) {
            throw new \InvalidArgumentException('weightBps must be integer 1..10000');
        }
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException('Invalid concept edge origin');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'fromChunkId' => $this->fromChunkId->value,
            'toChunkId' => $this->toChunkId->value,
            'relation' => $this->relation,
            'weightBps' => $this->weightBps,
            'origin' => $this->origin,
        ]);
    }
}
