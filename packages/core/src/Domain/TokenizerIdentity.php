<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject};

/** Identity of the exact local vocabulary/algorithm, never a provider alias. */
final readonly class TokenizerIdentity
{
    public function __construct(public AdapterId $id, public string $version, public Sha256 $vocabularyDigest)
    {
        ValueGuard::text($version, 64);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['tokenizerId'=>$this->id->value, 'tokenizerVersion'=>$this->version,
            'vocabularyDigest'=>$this->vocabularyDigest->value]);
    }

    public function digest(): Fingerprint
    {
        return new Fingerprint(hash('sha256', Jcs::canonicalize($this->toWire())));
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id)
            && $this->version === $other->version
            && $this->vocabularyDigest->equals($other->vocabularyDigest);
    }

    public function assertCompatible(self $other): void
    {
        if (!$this->digest()->equals($other->digest())) {
            throw new DomainFailure(Outcome::Incompatible, 'tokenizer_mismatch');
        }
    }
}
