<?php

declare(strict_types=1);

namespace KnowKit\Domain\Origins;

final readonly class FetchLimits
{
    public function __construct(
        public int $maxBytes = 67108864,
        public int $connectSeconds = 5,
        public int $totalSeconds = 60,
        public int $maxRedirects = 3,
    ) {
        if ($maxBytes < 1 || $maxBytes > 67108864 || $connectSeconds < 1 || $connectSeconds > 5
            || $totalSeconds < 1 || $totalSeconds > 60 || $maxRedirects < 0 || $maxRedirects > 3) {
            throw new \InvalidArgumentException('Finite connector ceilings required');
        }
    }
}
