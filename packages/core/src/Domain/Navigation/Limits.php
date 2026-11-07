<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class Limits
{
    public function __construct(
        public int $maxUnits = 24,
        public int $maxRangeWidth = 24,
        public int $maxCandidates = 16,
        public int $maxTextBytes = 8192,
    ) {
        foreach ([[$maxUnits, 24], [$maxRangeWidth, 24], [$maxCandidates, 16], [$maxTextBytes, 8192]] as [$value, $cap]) {
            if ($value < 1 || $value > $cap) {
                throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
            }
        }
    }
}
