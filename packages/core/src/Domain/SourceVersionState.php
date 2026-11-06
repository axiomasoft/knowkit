<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class SourceVersionState
{
    public function __construct(public TenantId $tenantId, public SourceVersionId $sourceVersionId,
        public Usability $usability, public ?SourceVersionId $correctedByVersionId, public EventId $eventId)
    {
        if ($correctedByVersionId !== null && ($usability !== Usability::Corrected || $sourceVersionId->equals($correctedByVersionId))) {
            throw new \InvalidArgumentException('Exact corrected replacement required');
        }
    }
}
