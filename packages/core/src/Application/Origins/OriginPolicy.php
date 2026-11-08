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

        return $parts;
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
