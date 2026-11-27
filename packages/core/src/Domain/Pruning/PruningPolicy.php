<?php

declare(strict_types=1);

namespace KnowKit\Domain\Pruning;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class PruningPolicy
{
    public function __construct(
        public int $thresholdPermille,
        public bool $alwaysKeepContext,
        public bool $keepAdjacentForCoreference,
        public int $minKeptBytes,
        public int $maxKeptBytes,
    ) {
        if (
            $thresholdPermille < 0 || $thresholdPermille > 1000
            || $minKeptBytes < 0 || $minKeptBytes > 262144
            || $maxKeptBytes < 1 || $maxKeptBytes > 262144
            || $minKeptBytes > $maxKeptBytes
        ) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
    }

    public static function fromWire(JsonObject $wire): self
    {
        $threshold = $wire->get('thresholdPermille');
        $alwaysKeepContext = $wire->get('alwaysKeepContext');
        $keepAdjacent = $wire->get('keepAdjacentForCoreference');
        $minKept = $wire->get('minKeptBytes');
        $maxKept = $wire->get('maxKeptBytes');

        if (
            !is_int($threshold)
            || !is_bool($alwaysKeepContext)
            || !is_bool($keepAdjacent)
            || !is_int($minKept)
            || !is_int($maxKept)
        ) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruning_policy');
        }

        return new self(
            thresholdPermille: $threshold,
            alwaysKeepContext: $alwaysKeepContext,
            keepAdjacentForCoreference: $keepAdjacent,
            minKeptBytes: $minKept,
            maxKeptBytes: $maxKept,
        );
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'thresholdPermille' => $this->thresholdPermille,
            'alwaysKeepContext' => $this->alwaysKeepContext,
            'keepAdjacentForCoreference' => $this->keepAdjacentForCoreference,
            'minKeptBytes' => $this->minKeptBytes,
            'maxKeptBytes' => $this->maxKeptBytes,
        ]);
    }
}
