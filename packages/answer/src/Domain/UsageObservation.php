<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Null means unobserved, never invented zero usage. */
final readonly class UsageObservation
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $providerRequestId = null,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
    ) {
        foreach ([$inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens] as $tokens) {
            if ($tokens !== null && $tokens < 0) {
                throw new \InvalidArgumentException('Nonnegative observed usage required');
            }
        }
        if ($providerRequestId !== null) {
            Guard::reference($providerRequestId);
        }
    }
}
