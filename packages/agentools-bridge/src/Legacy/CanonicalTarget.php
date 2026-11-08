<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Legacy;

use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\SnapshotRef;
use KnowKit\Domain\ValueGuard;

/** Supplied only after the host has independently validated original bytes and canonical IR. */
final readonly class CanonicalTarget
{
    /** @param list<CitationBinding> $citations */
    public function __construct(
        public DocumentRevisionRef $revision,
        public SnapshotRef $snapshot,
        public array $citations,
    ) {
        ValueGuard::objects(values: $citations, type: CitationBinding::class);
        if ($citations === []) {
            throw new \InvalidArgumentException('Exact citation bindings required');
        }
        if (!$revision->sourceRef->tenantId->equals($snapshot->tenantId)) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'scope_mismatch');
        }
        $seen = [];
        foreach ($citations as $binding) {
            $this->assertCitation($binding);
            $key = Jcs::canonicalize($binding->legacy->toWire());
            if (isset($seen[$key])) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
            }
            $seen[$key] = true;
        }
    }

    private function assertCitation(CitationBinding $binding): void
    {
        $wire = $binding->canonical->toWire();
        foreach ($this->revision->sourceRef->toWire() as $name => $value) {
            if ($wire->get($name) !== $value) {
                throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
            }
        }
        if ($wire->get('documentRevisionId') !== $this->revision->documentRevisionId->value
            || $wire->get('irDigest') !== $this->revision->irDigest->value) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'immutable_conflict');
        }
    }
}
