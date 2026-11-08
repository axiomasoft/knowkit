<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Scope;

final readonly class Estimate
{
    public function __construct(
        public Scope $scope,
        public int $inputBytes,
        public ?int $inputTokens,
        public int $maxOutputTokens,
        public int $maxOutputBytes,
    ) {
        if ($inputBytes < 0 || ($inputTokens !== null && $inputTokens < 0)
            || $maxOutputTokens < 1 || $maxOutputBytes < 1) {
            throw new \InvalidArgumentException('Bounded nonnegative estimate required');
        }
    }
}
