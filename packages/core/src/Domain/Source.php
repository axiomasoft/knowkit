<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Source
{
    public function __construct(public TenantId $tenantId, public SourceId $sourceId, public SourceLifecycleState $lifecycleState,
        public Decimal $lifecycleEpoch, public Decimal $sourcePolicyEpoch, public bool $retentionHold,
        public string $ownerRef, public string $origin)
    {
        ValueGuard::text($ownerRef); ValueGuard::text($origin);
    }
}
