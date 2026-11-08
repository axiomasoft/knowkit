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
    /** Validate every initial/redirect URL and ALL DNS answers before any privileged connection. */
    public static function https(string $url, array $resolvedAddresses): string
    {
        $p = OriginPolicy::parts($url);
        if ($resolvedAddresses === []) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $host = trim($p['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $resolvedAddresses[] = $host;
        }
        foreach ($resolvedAddresses as $ip) {
            PublicAddress::require($ip);
        }

        return $url;
    }

    public static function storageKey(string $key): string
    {
        if (strlen($key) > 512 || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $key) !== 1 || str_contains($key, '..')) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_path');
        }

        return $key;
    }
}
