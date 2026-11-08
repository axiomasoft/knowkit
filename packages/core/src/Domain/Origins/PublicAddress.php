<?php

declare(strict_types=1);

namespace KnowKit\Domain\Origins;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final class PublicAddress
{
    public static function require(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        $ranges = str_contains($address, ':')
            ? ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']
            : ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        if (str_contains($address, ':') && !self::contains(address: $address, cidr: '2000::/3')) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
        }
        foreach ($ranges as $range) {
            if (self::contains(address: $address, cidr: $range)) {
                throw new DomainFailure(Outcome::Denied, 'unsafe_origin');
            }
        }
    }

    private static function contains(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $packed = inet_pton($address);
        $prefix = inet_pton($network);
        if ($packed === false || $prefix === false || strlen($packed) !== strlen($prefix)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $tail = (int) $bits % 8;

        return substr($packed, 0, $bytes) === substr($prefix, 0, $bytes)
            && ($tail === 0 || ((ord($packed[$bytes]) ^ ord($prefix[$bytes])) & (255 << (8 - $tail))) === 0);
    }
}
