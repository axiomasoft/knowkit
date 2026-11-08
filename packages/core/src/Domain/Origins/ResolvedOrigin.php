<?php

declare(strict_types=1);

namespace KnowKit\Domain\Origins;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** A single DNS observation, pinned for exactly one connection. No credentials or headers. */
final readonly class ResolvedOrigin
{
    public function __construct(
        public string $url,
        public string $host,
        public string $address,
        public string $target,
    ) {
        PublicAddress::require($address);
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host) !== 1
            || !str_starts_with($target, '/') || strlen($target) > 4096
            || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $target) === 1) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
    }

    public function assertPeer(string $address): void
    {
        PublicAddress::require($address);
        if (inet_pton($this->address) !== inet_pton($address)) {
            throw new DomainFailure(Outcome::Denied, 'origin_peer_mismatch');
        }
    }

    public function __debugInfo(): array
    {
        return ['connector' => 'https'];
    }
}
