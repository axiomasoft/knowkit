<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Correction
{
    public function __construct(public SourceVersionRef $oldVersion, public ?SourceVersionRef $replacement,
        public Decimal $expectedLifecycleEpoch)
    {
        if ($replacement !== null && (!$oldVersion->tenantId->equals($replacement->tenantId)
            || !$oldVersion->sourceId->equals($replacement->sourceId) || $oldVersion->sourceVersionId->equals($replacement->sourceVersionId))) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }
}
