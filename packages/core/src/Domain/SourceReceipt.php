<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SourceReceipt
{
    public function __construct(public TenantId $tenantId, public SourceId $sourceId, public Decimal $lifecycleEpoch, public EventId $eventId) {}
}
