<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** No grant, decision, token, query text, canonical text or evidence payload is retained. */
final readonly class ReferenceCacheEntry
{
    /** @var list<Candidate> */
    public array $candidates;

    /** @param list<Candidate> $candidates */
    public function __construct(
        public ReferenceCacheKey $key,
        array $candidates,
        public UtcInstant $createdAt,
        public UtcInstant $expiresAt,
    ) {
        ValueGuard::objects($candidates, Candidate::class, 512);
        if ($createdAt->compare($expiresAt) >= 0 || $expiresAt->compare($createdAt->plusSeconds(300)) > 0) {
            throw new DomainFailure(Outcome::Incompatible, 'cache_lifetime_invalid');
        }
        foreach ($candidates as $candidate) {
            if ($candidate->ref->revisionRef->sourceRef->tenantId != $key->tenantId) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
        }
        $this->candidates = array_map(static fn (Candidate $candidate): Candidate =>
            new Candidate($candidate->ref, $candidate->rank), $candidates);
    }

    public function requireBinding(ReferenceCacheKey $key, UtcInstant $now): void
    {
        if ($this->key != $key) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        if ($this->createdAt->compare($now) > 0 || $this->expiresAt->compare($now) <= 0) {
            throw new DomainFailure(Outcome::Denied, 'cache_expired');
        }
    }
}
