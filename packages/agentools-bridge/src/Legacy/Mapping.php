<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\CitationRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ValueGuard;

/** Immutable read adapter record; identity resolution is never access authorization. */
final readonly class Mapping
{
    /** @param list<Locator> $locators */
    private function __construct(
        public ReleaseIdentity $release,
        public ?CanonicalTarget $target,
        public ?LimitedEvidence $limitedEvidence,
        public array $locators,
    ) {
        ValueGuard::objects(values: $locators, type: Locator::class);
        foreach ($locators as $locator) {
            if ($locator->release->key() !== $release->key()) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
            }
        }
    }

    public static function fromOriginals(ReleaseIdentity $release, CanonicalTarget $target): self
    {
        if (!$release->tenantId->equals($target->snapshot->tenantId)) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch');
        }

        return new self(
            release: $release,
            target: $target,
            limitedEvidence: null,
            locators: array_map(static fn (CitationBinding $binding): Locator => $binding->legacy, $target->citations),
        );
    }

    /** @param list<Locator> $locators */
    public static function withoutOriginals(LimitedEvidence $evidence, array $locators): self
    {
        return new self(
            release: $evidence->release,
            target: null,
            limitedEvidence: $evidence,
            locators: $locators,
        );
    }

    public function verificationLevel(): string
    {
        return $this->target === null ? 'legacy_unverified_evidence' : 'originals_verified';
    }

    public function citationFor(Locator $locator): ?CitationRef
    {
        if ($this->target === null || $locator->release->key() !== $this->release->key()) {
            return null;
        }
        foreach ($this->target->citations as $binding) {
            if ($binding->legacy->toWire() == $locator->toWire()) {
                return $binding->canonical;
            }
        }

        return null;
    }
}
