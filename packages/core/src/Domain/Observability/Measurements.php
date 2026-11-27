<?php

declare(strict_types=1);

namespace KnowKit\Domain\Observability;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Sha256;

final readonly class Measurements
{
    public function __construct(
        public int $durationMs = 0,
        public int $count = 1,
        public ?int $outboxLagSeconds = null,
        public ?int $orphanCount = null,
        public ?int $quotaUsed = null,
        public ?int $quotaLimit = null,
        public ?Sha256 $digestRef = null,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $uncachedInputTokens = null,
        public ?bool $prefixDriftDetected = null,
    ) {
        foreach ([$durationMs, $count, $outboxLagSeconds, $orphanCount, $quotaUsed, $quotaLimit, $cacheReadTokens, $cacheWriteTokens, $uncachedInputTokens] as $value) {
            if ($value !== null && ($value < 0 || $value > StrictJson::SAFE_INTEGER)) {
                throw new \InvalidArgumentException('Nonnegative safe integer measurement required');
            }
        }
    }
}
