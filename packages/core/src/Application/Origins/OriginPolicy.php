<?php

declare(strict_types=1);

namespace KnowKit\Application\Origins;

use KnowKit\Domain\Origins\PublicAddress;
use KnowKit\Domain\Origins\ResolvedOrigin;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Port\OriginResolver;

/** Explicit host configuration. An empty allowlist refuses all remote fetches. */
final readonly class OriginPolicy
{
    /** @param list<string> $allowedHosts Exact lowercase DNS names; no wildcards or suffix matching. */
    public function __construct(private array $allowedHosts = [])
    {
        foreach ($allowedHosts as $host) {
            if (!self::hostname($host) || $host !== strtolower($host) || self::local($host)) {
                throw new \InvalidArgumentException('Exact public DNS allowlist required');
            }
        }
    }

    public function resolve(string $url, OriginResolver $resolver, ?int $deadline = null): ResolvedOrigin
    {
        $parts = self::parts($url);
        $host = strtolower($parts['host']);
        if (!self::hostname($host) || self::local($host) || !in_array($host, $this->allowedHosts, true)) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $addresses = $resolver->addresses(host: $host, deadline: $deadline);
        if ($addresses === [] || count($addresses) > 64) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_dns_unavailable');
        }
        foreach ($addresses as $address) {
            PublicAddress::require($address);
        }
        $target = ($parts['path'] ?? '/') ?: '/';
        $target .= isset($parts['query']) ? '?' . $parts['query'] : '';

        return new ResolvedOrigin(url: $url, host: $host, address: $addresses[0], target: $target);
    }

    /** @return array<string, mixed> */
    public static function parts(string $url): array
    {
        $parts = parse_url($url);
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url) === 1
            || $parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['port'] ?? 443) !== 443 || (isset($parts['path']) && !str_starts_with($parts['path'], '/'))) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        // parse_url collapses :0443, :443.1 and :443e0 to port 443 and drops the suffix.
        self::assertCanonicalAuthority($url, strtolower((string) $parts['host']));

        return $parts;
    }

    private static function assertCanonicalAuthority(string $url, string $host): void
    {
        $rest = substr($url, 8);
        if (strlen($rest) < strlen($host) || substr_compare($rest, $host, 0, strlen($host), true) !== 0) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $after = substr($rest, strlen($host));
        if ($after === '' || $after[0] === '/' || $after[0] === '?') {
            return;
        }
        if (!str_starts_with($after, ':443')) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $tail = substr($after, 4);
        if ($tail !== '' && $tail[0] !== '/' && $tail[0] !== '?') {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
    }

    private static function hostname(string $host): bool
    {
        return strlen($host) <= 253 && str_contains($host, '.')
            && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/Di', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    private static function local(string $host): bool
    {
        foreach (['localhost', 'local', 'internal', 'home.arpa'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
