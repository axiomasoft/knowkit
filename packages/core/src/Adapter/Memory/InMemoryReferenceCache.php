<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Memory;

use KnowKit\Domain as D;
use KnowKit\Port\Clock;
use KnowKit\Port\ReferenceCache;

/**
 * Bounded process-local LRU, adapting DeepTutor generation-scoped cache lifetime/isolation.
 * Copyright 2025 Data Intelligence Lab, The University of Hong Kong. Apache-2.0.
 * Notices: docs/architecture/third-party/DeepTutor-*; pins: P4.4-reuse.json.
 * No durable evidence or authorization state.
 */
final class InMemoryReferenceCache implements ReferenceCache
{
    /** @var array<string, D\ReferenceCacheEntry> */
    private array $entries = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $capacity = 128,
    ) {
        if ($capacity < 1 || $capacity > 4096) {
            throw new \InvalidArgumentException('Bounded reference cache capacity required');
        }
    }

    public function get(D\Scope $scope, D\ReferenceCacheKey $key): ?D\ReferenceCacheEntry
    {
        $key->assertScope($scope);
        $id = $key->digest->value;
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            return null;
        }
        $now = $this->clock->now();
        if ($entry->expiresAt->compare($now) <= 0 || $entry->createdAt->compare($now) > 0) {
            unset($this->entries[$id]);

            return null;
        }
        $entry->requireBinding($key, $now);
        unset($this->entries[$id]);
        $this->entries[$id] = $entry;

        return $entry;
    }

    public function put(D\Scope $scope, D\ReferenceCacheEntry $entry): void
    {
        $entry->key->assertScope($scope);
        $entry->requireBinding($entry->key, $this->clock->now());
        $id = $entry->key->digest->value;
        unset($this->entries[$id]);
        $this->entries[$id] = $entry;
        while (count($this->entries) > $this->capacity) {
            array_shift($this->entries);
        }
    }

    public function invalidate(D\TenantId $tenant): void
    {
        foreach ($this->entries as $id => $entry) {
            if ($entry->key->tenantId->equals($tenant)) {
                unset($this->entries[$id]);
            }
        }
    }
}
