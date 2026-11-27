<?php

declare(strict_types=1);

namespace KnowKit\Domain\Pruning;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ValueGuard;

final readonly class AtomicByteRange
{
    public function __construct(
        public int $startByte,
        public int $endByte,
        public string $kind = 'atomic',
    ) {
        if ($startByte < 0 || $endByte <= $startByte) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_pruned_subspan');
        }
        ValueGuard::text($kind, 64);
    }

    public function byteLength(): int
    {
        return $this->endByte - $this->startByte;
    }

    public function overlaps(int $start, int $end): bool
    {
        return $this->startByte < $end && $start < $this->endByte;
    }

    public function isContainedIn(int $start, int $end): bool
    {
        return $start <= $this->startByte && $this->endByte <= $end;
    }
}
