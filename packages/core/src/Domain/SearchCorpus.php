<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Catalog inventory and fresh prefiltered scope for one exact generation. */
final readonly class SearchCorpus
{
    public function __construct(
        public AuthorizedSearchScope $scope,
        public SelectionInventory $inventory,
    ) {
        $snapshot = $inventory->snapshot;
        if ($scope->tenantId != $snapshot->tenantId || $scope->kbId != $snapshot->kbId
            || $scope->snapshotId != $snapshot->snapshotId || $scope->generationId != $inventory->generationId()) {
            throw new DomainFailure(Outcome::Incompatible, 'snapshot_mismatch');
        }
        $this->assertSpace($scope->embeddingSpaceDigest);
    }

    public function assertSpace(?Fingerprint $expected): void
    {
        if ($this->inventory->manifest->manifest->toWire()->get('embeddingSpaceDigest') !== $expected?->value
            || $this->scope->embeddingSpaceDigest != $expected) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
    }

    public function assertProfile(string $id, string $kind): void
    {
        $profile = $this->inventory->manifest->manifest->toWire()->get('profile');
        if ($profile->get('id') !== $id || $profile->get('kind') !== $kind) {
            throw new DomainFailure(Outcome::Incompatible, 'profile_invalid');
        }
    }

    public function assertBinding(Scope $host): void
    {
        if ($host->tenantId != $this->scope->tenantId || $host->kbId != $this->scope->kbId
            || $host->hostScopeHash != $this->scope->hostScopeHash
            || $host->bindingDigest($this->scope->policyBindingDigest) != $this->scope->scopeBindingDigest) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function assertCompanion(self $other): void
    {
        if ($this->inventory->snapshot != $other->inventory->snapshot
            || $this->versions() !== $other->versions()
            || $this->scope->policyBindingDigest != $other->scope->policyBindingDigest) {
            throw new DomainFailure(Outcome::Incompatible, 'snapshot_mismatch');
        }
    }

    /** @return list<string> */
    private function versions(): array
    {
        $versions = array_map(static fn (SourceVersionId $id): string => $id->value, $this->scope->allowedSourceVersionIds);
        sort($versions, SORT_STRING);

        return $versions;
    }

    public function assertList(CandidateList $list, int $topK): void
    {
        if ($list->scope != $this->scope) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        if (count($list->candidates) > $topK) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
        foreach ($list->candidates as $candidate) {
            $this->inventory->manifest->assertCandidate($candidate->ref);
        }
    }
}
