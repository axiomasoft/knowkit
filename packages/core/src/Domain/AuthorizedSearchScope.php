<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

/** Materialized before backend LIMIT. eligibleVersions already intersects host/KB/collection/purpose rights. */
final readonly class AuthorizedSearchScope
{
    /** @param list<SourceVersionId> $allowedSourceVersionIds */
    private function __construct(public TenantId $tenantId, public KbId $kbId, public SnapshotId $snapshotId,
        public GenerationId $generationId, public array $allowedSourceVersionIds, public Sha256 $policyBindingDigest,
        public ?Fingerprint $embeddingSpaceDigest, public Sha256 $hostScopeHash, public Fingerprint $scopeBindingDigest) {}

    /** @param list<SourceVersionId> $eligibleVersions */
    public static function materialize(Scope $scope, QueryGrant $grant, SnapshotRef $snapshot, GenerationId $generation,
        array $eligibleVersions, UtcInstant $now, ?Fingerprint $space = null, ?Query $query = null,
        ?CurrentMemberships $memberships = null): self
    {
        $scope->assertTenant($snapshot->tenantId);
        if (!$scope->kbId->equals($snapshot->kbId)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
        ValueGuard::uniqueIds($eligibleVersions, SourceVersionId::class);
        $wire = $grant->toWire();
        if ($wire->get('grantEpoch') !== $scope->policyEpoch->value) { throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED'); }
        if ((new UtcInstant($wire->get('expiresAt')))->compare($now) <= 0) { throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED'); }
        $allowed = array_values(array_filter($eligibleVersions, static fn(SourceVersionId $id): bool =>
            in_array($id->value, $wire->get('allowedSourceVersionIds'), true)));
        if ($query?->sourceVersionIds !== null) {
            $requested = array_map(static fn(SourceVersionId $id): string => $id->value, $query->sourceVersionIds);
            $allowed = array_values(array_filter($allowed, static fn(SourceVersionId $id): bool => in_array($id->value, $requested, true)));
        }
        // Collection filters need authoritative memberships. Do not guess them or broaden the universe.
        if ($query?->collectionIds !== null) {
            if ($memberships === null) { throw new DomainFailure(Outcome::Incompatible, 'collection_filter_requires_memberships'); }
            if ($memberships->scope != $scope) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            $collections = array_values(array_intersect(
                array_map(static fn(CollectionId $id): string => $id->value, $query->collectionIds),
                $wire->get('allowedCollectionIds')));
            $allowed = array_values(array_filter($allowed, static function (SourceVersionId $id) use ($memberships, $collections): bool {
                $memberOf = array_map(static fn(CollectionId $id): string => $id->value, $memberships->bySourceVersion[$id->value] ?? []);
                return array_intersect($collections, $memberOf) !== [];
            }));
        }
        $binding = new Sha256($wire->get('policyBindingDigest'));
        return new self($scope->tenantId, $scope->kbId, $snapshot->snapshotId, $generation, $allowed, $binding,
            $space, $scope->hostScopeHash, $scope->bindingDigest($binding));
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['tenantId'=>$this->tenantId->value,'kbId'=>$this->kbId->value,
            'snapshotId'=>$this->snapshotId->value,'generationId'=>$this->generationId->value,
            'allowedSourceVersionIds'=>array_map(static fn(SourceVersionId $id): string => $id->value, $this->allowedSourceVersionIds),
            'policyBindingDigest'=>$this->policyBindingDigest->value,'embeddingSpaceDigest'=>$this->embeddingSpaceDigest?->value]);
    }
    public function assertCandidate(ChunkRef $ref): void
    {
        if (!$this->tenantId->equals($ref->revisionRef->sourceRef->tenantId)
            || !in_array($ref->revisionRef->sourceRef->sourceVersionId->value,
                array_map(static fn(SourceVersionId $id): string => $id->value, $this->allowedSourceVersionIds), true)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }
}
