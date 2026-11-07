<?php

declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;

/**
 * Adapts AgenTools Query::cacheKey (c) 2026 Dmitry Vostrikov, authorized private local reuse.
 * Pins/notices: tests/retrieval/cache/evidence/P4.4-reuse.json.
 * Retains only a digest and trusted partition identifiers.
 */
final readonly class ReferenceCacheKey
{
    private function __construct(
        public TenantId $tenantId,
        public Sha256 $hostScopeHash,
        public Fingerprint $digest,
    ) {}

    public static function bind(
        PolicyContext $policy,
        AuthorizedSearchScope $search,
        SnapshotRef $snapshot,
        QueryBinding $query,
        int $topK,
    ): self {
        if ($topK < 1 || $topK > 512) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
        if ($search->tenantId != $policy->scope->tenantId || $search->kbId != $policy->scope->kbId
            || $search->snapshotId != $snapshot->snapshotId || $snapshot->tenantId != $search->tenantId
            || $snapshot->kbId != $search->kbId || $search->hostScopeHash != $policy->scope->hostScopeHash
            || $search->scopeBindingDigest != $policy->scope->bindingDigest($search->policyBindingDigest)) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        $versions = array_map(static fn (SourceVersionId $id): string => $id->value, $search->allowedSourceVersionIds);
        sort($versions, SORT_STRING);
        $binding = new JsonObject([
            'revision' => 'reference-cache:1',
            'hostPolicy' => $policy->binding(),
            'search' => $search->toWire()->with('allowedSourceVersionIds', $versions),
            'snapshot' => $snapshot->toWire(),
            'queryDigest' => $query->digest()->value,
            'topK' => $topK,
        ]);

        return new self($search->tenantId, $search->hostScopeHash,
            new Fingerprint(HashDomain::RetrievalCacheKey->digest($binding)));
    }

    public function assertScope(Scope $scope): void
    {
        $scope->assertTenant($this->tenantId);
        if ($scope->hostScopeHash != $this->hostScopeHash) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
    }
}
