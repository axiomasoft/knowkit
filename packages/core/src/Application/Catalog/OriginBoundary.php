<?php

declare(strict_types=1);

namespace KnowKit\Application\Catalog;

use KnowKit\Application\Origins\OriginPolicy;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Origins\PublicAddress;
use KnowKit\Domain\Outcome;

/** Compatibility preflight only. Privileged fetches use the explicit FetchRemote connector. */
final class OriginBoundary
{
    /**
     * Validate every initial/redirect URL and ALL DNS answers before any privileged connection.
     *
     * @param list<string> $resolvedAddresses
     */
    public static function https(string $url, array $resolvedAddresses): string
    {
        $p = OriginPolicy::parts($url);
        if ($resolvedAddresses === []) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $host = strtolower(trim($p['host'], '[]'));
        // glibc inet_aton accepts hex, octal, short, and decimal forms that filter_var misses.
        // Those hosts must not pass just because a caller also supplied an unrelated public address.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $resolvedAddresses[] = $host;
        } elseif (self::alternateAddress($host) || self::localName($host)) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        foreach ($resolvedAddresses as $ip) {
            PublicAddress::require($ip);
        }

        return $url;
    }

    private static function alternateAddress(string $host): bool
    {
        if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)\z/D', $host) === 1) {
            return true;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)\z/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function localName(string $host): bool
    {
        foreach (['localhost', 'local', 'internal', 'home.arpa'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function storageKey(string $key): string
    {
        if (strlen($key) > 512 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $key) !== 1 || str_contains($key, '..')) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_path');
        }

        return $key;
    }
}
