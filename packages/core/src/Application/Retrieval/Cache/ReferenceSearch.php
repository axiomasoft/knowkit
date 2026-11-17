<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Cache;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain as D;
use KnowKit\Port\Clock;
use KnowKit\Port\LexicalIndex;
use KnowKit\Port\ReferenceCache;

/** A hit only replaces backend ranking. Call after fresh QueryGrant and exact catalog load. */
final readonly class ReferenceSearch
{
    public function __construct(
        private LexicalIndex $index,
        private Clock $clock,
        private ?ReferenceCache $cache = null,
    ) {}

    public function candidates(
        AccessContext $context,
        D\AuthorizedSearchScope $scope,
        D\SelectionInventory $inventory,
        D\QueryBinding $query,
        int $topK,
    ): D\ReferenceCacheEntry {
        $key = D\ReferenceCacheKey::bind($context->policy, $scope, $inventory->snapshot, $query, $topK);
        $entry = $this->cache?->get($context->scope(), $key);
        if ($entry !== null) {
            $entry->requireBinding($key, $this->clock->now());
            if (count($entry->candidates) > $topK) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
            }

            return $entry;
        }
        $startedAt = $this->clock->now();
        $candidates = $this->index->search($scope, $query->normalized, $topK);
        if (!$candidates->scope->equals($scope)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        if (count($candidates->candidates) > $topK) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
        }

        return new D\ReferenceCacheEntry($key, $candidates->candidates, $startedAt, $startedAt->plusSeconds(30));
    }

    /** Only called after successful selection/store and current host checks; no in-flight promises. */
    public function remember(
        AccessContext $context,
        D\ReferenceCacheEntry $entry,
    ): void {
        if ($this->cache === null || $entry->expiresAt->compare($this->clock->now()) <= 0) {
            return;
        }
        $context->assertCurrent();
        $this->cache->put($context->scope(), $entry);
    }
}
