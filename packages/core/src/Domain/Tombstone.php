<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Tombstone
{
    public string $appliesTo;
    public function __construct(public TenantId $tenantId, public SourceId $sourceId, public string $reason,
        public EventId $eventId, public Decimal $lifecycleEpoch)
    {
        if (!in_array($reason, ['source_revoked','source_deleted','consent_revoked'], true)) {
            throw new \InvalidArgumentException('Tombstone reason required');
        }
        $this->appliesTo = 'future_versions';
    }
}
