<?php

declare(strict_types=1);

namespace KnowKit\Application\Origins;

use KnowKit\Domain\Origins\DocumentInput;
use KnowKit\Domain\Origins\ResolvedOrigin;
use KnowKit\Domain\Origins\FetchLimits;
use KnowKit\Domain\Origins\RemoteResponse;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Port\OriginResolver;
use KnowKit\Port\OriginTransport;

/** Optional explicit connector; catalog origin references are never fetch targets. */
final readonly class FetchRemote
{
    public function __construct(
        private OriginPolicy $policy,
        private OriginResolver $resolver,
        private OriginTransport $transport,
        private FetchLimits $limits = new FetchLimits(),
    ) {}

    public function fetch(#[\SensitiveParameter] string $url): RemoteResponse
    {
        $deadline = hrtime(true) + $this->limits->totalSeconds * 1_000_000_000;
        for ($redirects = 0; ; $redirects++) {
            $origin = $this->policy->resolve(url: $url, resolver: $this->resolver, deadline: $deadline);
            if (hrtime(true) >= $deadline) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_timeout');
            }
            $response = $this->transport->get(origin: $origin, limits: $this->limits, deadline: $deadline);
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($redirects >= $this->limits->maxRedirects || !isset($response->headers['location'])) {
                    throw new DomainFailure(Outcome::Denied, 'origin_redirect_limit');
                }
                $url = $this->redirect(origin: $origin, location: $response->headers['location']);
                continue;
            }
            if ($response->status !== 200) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_http_failure');
            }
            DocumentInput::assertMime(bytes: $response->bytes(), mediaType: $response->headers['content-type'] ?? '');

            return $response;
        }
    }

    private function redirect(ResolvedOrigin $origin, string $location): string
    {
        // Only absolute HTTPS and origin-relative paths. Ambiguous relative targets fail closed.
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return 'https://' . $origin->host . $location;
        }

        return $location;
    }
}
