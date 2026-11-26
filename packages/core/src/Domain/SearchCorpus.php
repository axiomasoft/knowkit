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
        if (!$scope->tenantId->equals($snapshot->tenantId)
            || !$scope->kbId->equals($snapshot->kbId)
            || !$scope->snapshotId->equals($snapshot->snapshotId)
            || !$scope->generationId->equals($inventory->generationId())) {
            throw new DomainFailure(Outcome::Incompatible, 'snapshot_mismatch');
        }
        $this->assertSpace($scope->embeddingSpaceDigest);
    }

    public function assertSpace(?Fingerprint $expected): void
    {
        $sameSpace = $this->scope->embeddingSpaceDigest === null
            ? $expected === null
            : ($expected !== null && $this->scope->embeddingSpaceDigest->equals($expected));
        if ($this->inventory->manifest->manifest->toWire()->get('embeddingSpaceDigest') !== $expected?->value
            || !$sameSpace) {
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
        if (!$host->tenantId->equals($this->scope->tenantId)
            || !$host->kbId->equals($this->scope->kbId)
            || !$host->hostScopeHash->equals($this->scope->hostScopeHash)
            || !$host->bindingDigest($this->scope->policyBindingDigest)->equals($this->scope->scopeBindingDigest)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }

    public function assertCompanion(self $other): void
    {
        if (!$this->inventory->snapshot->equals($other->inventory->snapshot)
            || $this->versions() !== $other->versions()
            || !$this->scope->policyBindingDigest->equals($other->scope->policyBindingDigest)) {
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
        if (!$list->scope->equals($this->scope)) {
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
