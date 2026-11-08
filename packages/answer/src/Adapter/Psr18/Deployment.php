<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

use KnowKit\Answer\Domain\AnswerLimits;
use KnowKit\Answer\Domain\ResolvedModelBinding;

/** Trusted explicit endpoint, never inferred from a provider name or model input. */
final readonly class Deployment
{
    public function __construct(
        public ResolvedModelBinding $binding,
        public string $endpoint,
        public AnswerLimits $limits = new AnswerLimits(),
        public HttpLimits $http = new HttpLimits(5000, 60000, 131072),
    ) {
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
            throw new \InvalidArgumentException('Explicit HTTPS endpoint required');
        }
    }
}
