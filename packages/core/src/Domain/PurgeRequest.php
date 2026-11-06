<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class PurgeRequest
{
    public function __construct(public SourceId $sourceId, public Decimal $expectedLifecycleEpoch) {}
}
