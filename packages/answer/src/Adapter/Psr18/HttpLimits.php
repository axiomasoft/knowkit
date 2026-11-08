<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

/** Host must enforce these at the socket and while buffering a PSR-18 response. */
final readonly class HttpLimits
{
    public function __construct(
        public int $connectMilliseconds,
        public int $totalMilliseconds,
        public int $responseBytes,
    ) {
        if ($connectMilliseconds < 1 || $connectMilliseconds > 5000
            || $totalMilliseconds < $connectMilliseconds || $totalMilliseconds > 120000
            || $responseBytes < 1 || $responseBytes > 1048576) {
            throw new \InvalidArgumentException('Finite HTTP limits required');
        }
    }
}
