<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class KnowledgeBase
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public Decimal $policyEpoch,
        public ?SnapshotId $activeSnapshotId, public Decimal $pointerVersion) {}
}
