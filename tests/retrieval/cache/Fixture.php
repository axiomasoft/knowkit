<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Cache;

use KnowKit\Adapter\Memory\InMemoryReferenceCache;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;

require_once dirname(__DIR__) . '/selection/bootstrap.php';

final class Fixture implements P\ReferenceCache
{
    public Host $host;
    public InMemoryReferenceCache $memory;
    public int $hits = 0;
    public int $puts = 0;
    public ?D\ReferenceCacheEntry $last = null;
    public ?\Closure $onGet = null;

    public function __construct()
    {
        $this->host = new Host();
        $this->memory = new InMemoryReferenceCache($this->host);
    }

    public function service(): Selections
    {
        return new Selections($this->host->checks(), $this->host, $this->host, $this->host->store,
            new BoundedSelector(), $this->host, $this->host, PortFixture::validator(), $this);
    }

    /** @phpstan-impure */
    public function retrieve(?D\QueryBinding $query = null): D\RetrievalOutcome
    {
        return $this->host->retrieve($query, $this->service());
    }

    public function key(?D\QueryBinding $query = null, ?D\SnapshotRef $snapshot = null,
        ?D\GenerationId $generation = null, int $topK = 64): D\ReferenceCacheKey
    {
        $query ??= $this->host->query();
        $snapshot ??= $this->host->inventory->snapshot;
        $search = $this->host->checks()->authorizeQuery($this->host->context(), $snapshot,
            $generation ?? $this->host->inventory->generationId(), $this->host->eligible,
            $this->host->binding, $query->original);

        return D\ReferenceCacheKey::bind($this->host->identity, $search, $snapshot, $query, $topK);
    }

    public function get(D\Scope $scope, D\ReferenceCacheKey $key): ?D\ReferenceCacheEntry
    {
        $entry = $this->memory->get($scope, $key);
        if ($entry !== null) {
            ++$this->hits;
        }
        if ($this->onGet !== null) {
            return ($this->onGet)($scope, $key, $entry);
        }

        return $entry;
    }

    public function put(D\Scope $scope, D\ReferenceCacheEntry $entry): void
    {
        ++$this->puts;
        $this->last = $entry;
        $this->memory->put($scope, $entry);
    }

    public function invalidate(D\TenantId $tenant): void
    {
        $this->memory->invalidate($tenant);
    }
}
