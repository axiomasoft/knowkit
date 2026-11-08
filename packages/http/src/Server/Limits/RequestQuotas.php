<?php

declare(strict_types=1);

namespace KnowKit\Http\Server\Limits;

use KnowKit\Adapter\LocalQuota\QuotaLease;
use KnowKit\Adapter\LocalQuota\TenantQuota;
use KnowKit\Domain\TenantId;

final readonly class RequestQuotas
{
    public function __construct(
        private TenantQuota $store = new TenantQuota(),
        private int $searchConcurrent = 16,
        private int $ingestConcurrent = 4,
        private int $searchPerMinute = 60,
        private int $ingestPerMinute = 10,
    ) {
        foreach ([$searchConcurrent, $ingestConcurrent] as $limit) {
            if ($limit < 1 || $limit > 1024) {
                throw new \InvalidArgumentException('Finite host quotas required');
            }
        }
        foreach ([$searchPerMinute, $ingestPerMinute] as $limit) {
            if ($limit < 1 || $limit > 1000000) {
                throw new \InvalidArgumentException('Finite host quotas required');
            }
        }
    }

    public function acquire(TenantId $tenant, string $operation): ?QuotaLease
    {
        if ($operation === 'search') {
            return $this->store->acquire(tenant: $tenant, lane: 'search', concurrent: $this->searchConcurrent, perMinute: $this->searchPerMinute);
        }
        if (in_array($operation, ['addVersion', 'startIngestJob', 'reprocess'], true)) {
            return $this->store->acquire(tenant: $tenant, lane: 'ingest', concurrent: $this->ingestConcurrent, perMinute: $this->ingestPerMinute);
        }

        return null;
    }
}
