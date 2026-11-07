<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\ScopeBinding;

/** Private immutable server record. Its public Selection contains references only. */
final readonly class SelectionRecord
{
    public function __construct(
        public Selection $selection,
        public PolicyContext $context,
        public QueryBinding $query,
        public SnapshotRef $snapshot,
        public UtcInstant $createdAt,
    ) {
        $wire = $selection->toWire();
        $check = ScopeBinding::checkSelection($context->scope->toWire(), $wire);
        $expiresAt = new UtcInstant($wire->get('expiresAt'));
        if ($check['outcome'] !== 'success' || $wire->get('queryDigest') !== $query->digest()->value
            || Jcs::canonicalize($wire->get('limits')) !== Jcs::canonicalize($query->limits->toWire())
            || $wire->get('snapshotId') !== $snapshot->snapshotId->value
            || $snapshot->tenantId != $context->scope->tenantId || $snapshot->kbId != $context->scope->kbId
            || $expiresAt->compare($createdAt->plusSeconds(1)) < 0 || $expiresAt->compare($createdAt->plusSeconds(300)) > 0) {
            throw new DomainFailure(Outcome::Incompatible, 'selection_binding_mismatch');
        }
    }

    public function requireBinding(
        PolicyContext $current,
        QueryBinding $query,
        SnapshotRef $snapshot,
        ?string $runRef,
        UtcInstant $now,
    ): void {
        $this->context->assertSame($current);
        if ($this->selection->expiresAt()->compare($now) <= 0 || $now->compare($this->createdAt) < 0) {
            throw new DomainFailure(Outcome::Denied, 'selection_expired');
        }
        if (!$this->query->digest()->equals($query->digest()) || $this->snapshot != $snapshot
            || $this->selection->runRef() !== $runRef) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function cacheKey(): Fingerprint
    {
        return new Fingerprint(HashDomain::RetrievalCacheKey->digest(new JsonObject([
            'scopeBindingDigest' => $this->selection->scopeBindingDigest()->value,
            'hostScopeHash' => $this->context->scope->hostScopeHash->value,
            'audience' => $this->context->audience,
            'grants' => $this->context->grants,
            'egress' => $this->context->egress,
            'queryDigest' => $this->query->digest()->value,
            'snapshotRef' => $this->snapshot->toWire(),
            'generationId' => $this->selection->generationId()->value,
            'runRef' => $this->selection->runRef(),
        ])));
    }

    public function __debugInfo(): array
    {
        return ['selection' => $this->selection];
    }
}
