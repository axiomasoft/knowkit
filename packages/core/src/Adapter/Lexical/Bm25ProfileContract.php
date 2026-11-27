<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Lexical;

use KnowKit\Domain\{Capability, DomainFailure, Outcome, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

/**
 * Contract for optional BM25 LexicalIndex adapters (e.g. ParadeDB pg_search on PostgreSQL 17).
 *
 * Invariants:
 * - Must enforce tenant, snapshot, source lifecycle, tombstone, and collection prefilters BEFORE ORDER BY / LIMIT.
 * - Deterministic tie-breaker: score DESC, chunk_id COLLATE "C" ASC.
 * - Zero float values in manifest/wire identity (integer-only RFC 8785 JCS); BM25 k1/b parameters are integer milli-units.
 * - Requires Capability::LexicalPrefilter ('lexical.prefilter').
 */
final readonly class Bm25ProfileContract
{
    public const DEFAULT_PROFILE_ID = 'pg_search-bm25-v1';
    public const REQUIRED_CAPABILITY = Capability::LexicalPrefilter;

    public function __construct(
        public string $profileId = self::DEFAULT_PROFILE_ID,
        public int $k1Milli = 1200,
        public int $bMilli = 750,
        public string $tokenizer = 'default',
    ) {
        ValueGuard::text($profileId, 80);
        ValueGuard::text($tokenizer, 80);
        if ($k1Milli < 0 || $k1Milli > 10000 || $bMilli < 0 || $bMilli > 1000) {
            throw new DomainFailure(Outcome::Incompatible, 'profile_invalid');
        }
    }

    /** @param list<Capability> $capabilities */
    public static function assertCapabilities(array $capabilities): void
    {
        if (!in_array(self::REQUIRED_CAPABILITY, $capabilities, true)) {
            throw new DomainFailure(Outcome::Incompatible, 'prefilter_unsupported');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'profileId' => $this->profileId,
            'k1Milli' => $this->k1Milli,
            'bMilli' => $this->bMilli,
            'tokenizer' => $this->tokenizer,
            'requiredCapability' => self::REQUIRED_CAPABILITY->value,
        ]);
    }
}
