<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class KnowledgeBase
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public Decimal $policyEpoch,
        public ?SnapshotId $activeSnapshotId, public Decimal $pointerVersion,
        public string $name, public Decimal $revision, public string $policyRef,
        public ProfileId $defaultRetrievalProfile, public CoveragePolicy $coveragePolicy)
    {
        ValueGuard::text($name, 128); ValueGuard::text($policyRef);
    }
}
