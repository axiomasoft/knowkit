<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;

/** Original query and filters are retained independently of optional text rewriting. */
final readonly class QueryBinding
{
    public function __construct(
        public Query $original,
        public Query $normalized,
        public string $normalizationRevision,
        public EffectiveProfile $profile,
        public SelectionLimits $limits,
        public ?Fingerprint $spaceFingerprint = null,
    ) {
        ValueGuard::text($normalizationRevision, 128);
        if ($this->rawIds($original->sourceVersionIds) !== $this->rawIds($normalized->sourceVersionIds)
            || $this->rawIds($original->collectionIds) !== $this->rawIds($normalized->collectionIds)) {
            throw new DomainFailure(Outcome::Incompatible, 'rewrite_filter_mismatch');
        }
        if (self::protectedTerms($original->text()) !== self::protectedTerms($normalized->text())) {
            throw new DomainFailure(Outcome::Incompatible, 'rewrite_identifier_mismatch');
        }
    }

    public function digest(): Fingerprint
    {
        return new Fingerprint(HashDomain::QueryBinding->digest($this->toWire()));
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'originalQuery' => $this->original->text(),
            'normalizedQuery' => $this->normalized->text(),
            'normalizationRevision' => $this->normalizationRevision,
            'sourceVersionIds' => $this->ids($this->original->sourceVersionIds),
            'collectionIds' => $this->ids($this->original->collectionIds),
            'effectiveProfileDigest' => $this->profile->digest()->value,
            'limits' => $this->limits->toWire(),
            'spaceFingerprint' => $this->spaceFingerprint?->value,
        ]);
    }

    /** @return list<string> */
    private static function protectedTerms(string $text): array
    {
        preg_match_all('~"[^"]+"|[\p{L}\p{N}]+(?:[-_./:][\p{L}\p{N}]+)+|\p{N}+~u', $text, $matches);
        $terms = array_values(array_unique($matches[0]));
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * @param list<Ulid>|null $ids
     * @return list<string>|null
     */
    private function rawIds(?array $ids): ?array
    {
        return $ids === null
            ? null
            : array_map(static fn (Ulid $id): string => $id->value, $ids);
    }

    /**
     * @param list<Ulid>|null $ids
     * @return list<string>|null
     */
    private function ids(?array $ids): ?array
    {
        $values = $this->rawIds($ids);
        if ($values === null) {
            return null;
        }
        sort($values, SORT_STRING);

        return $values;
    }

    public function __debugInfo(): array
    {
        return ['queryDigest' => $this->digest()];
    }
}
