<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Revocation
{
    public function __construct(public SourceId $sourceId, public Decimal $expectedLifecycleEpoch,
        public string $reason = 'source_revoked')
    {
        if (!in_array($reason, ['source_revoked','consent_revoked'], true)) { throw new \InvalidArgumentException('Revocation reason required'); }
    }
}
