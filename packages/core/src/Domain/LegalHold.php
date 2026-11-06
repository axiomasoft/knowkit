<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class LegalHold
{
    public function __construct(public SourceId $sourceId, public bool $enabled, public Decimal $expectedLifecycleEpoch) {}
}
