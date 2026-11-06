<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/** Untagged digests: raw bytes and irDigest = SHA256(JCS(IR)) without prefix. */
final class Digests
{
    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function ir(JsonObject $ir): string
    {
        return hash('sha256', Jcs::canonicalize($ir));
    }

    /** Remove a self digest field before hashing; only the top-level member is excluded. */
    public static function withoutSelf(JsonObject $payload, string $field): JsonObject
    {
        return $payload->without($field);
    }
}
