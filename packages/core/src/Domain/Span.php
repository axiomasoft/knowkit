<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** pageNo is an accessor for physicalNo, never an alternative serialized field. */
final readonly class Span
{
    public function __construct(public TextLocator $locator, public Sha256 $textDigest) {}
    public function pageNo(): ?int { return $this->locator->physicalNo(); }
    public function slice(string $canonicalText): string
    {
        if (!hash_equals($this->textDigest->value, hash('sha256', $canonicalText))) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        return $this->locator->slice($canonicalText);
    }
}
