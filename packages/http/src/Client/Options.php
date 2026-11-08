<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

final readonly class Options
{
    public function __construct(
        public int $timeoutMs = 5000,
        public int $maxAttempts = 3,
        public int $retryDelayMs = 100,
        public string $schemaVersion = '1.0',
    ) {
        if ($timeoutMs < 1 || $timeoutMs > 300000 || $maxAttempts < 1 || $maxAttempts > 5
            || $retryDelayMs < 0 || $retryDelayMs > 60000 || $schemaVersion !== '1.0') {
            throw new \InvalidArgumentException('Invalid HTTP client options');
        }
    }
}
