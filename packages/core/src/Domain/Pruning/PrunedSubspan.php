<?php

declare(strict_types=1);

namespace KnowKit\Domain\Pruning;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class PrunedSubspan
{
    public function __construct(
        public int $startByte,
        public int $endByte,
        public ?int $scorePermille = null,
    ) {
        if ($startByte < 0 || $endByte <= $startByte) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        if ($scorePermille !== null && ($scorePermille < 0 || $scorePermille > 1000)) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
    }

    public function byteLength(): int
    {
        return $this->endByte - $this->startByte;
    }
}
