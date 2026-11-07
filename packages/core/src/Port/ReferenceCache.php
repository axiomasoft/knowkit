<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\ReferenceCacheEntry;
use KnowKit\Domain\ReferenceCacheKey;
use KnowKit\Domain\Scope;
use KnowKit\Domain\TenantId;

/** Optional reference acceleration. Implementations must never store permission or plaintext. */
interface ReferenceCache
{
    public function get(Scope $scope, ReferenceCacheKey $key): ?ReferenceCacheEntry;

    public function put(Scope $scope, ReferenceCacheEntry $entry): void;

    /** Conservative tenant eviction; events never insert entries or confer authorization. */
    public function invalidate(TenantId $tenant): void;
}
