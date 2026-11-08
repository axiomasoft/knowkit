<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

final readonly class EvidenceContextRequest
{
    public function __construct(
        public string $turnId,
        public string $runId,
        public string $invocationUseRef,
        public string $snapshotUseRef,
        public string $bundleRef,
        public string $bundleHash,
        public string $hostScopeHash,
        public int $maxFragments,
        public int $maxBytes,
        public string $operation,
        public string $now,
    ) {
        Contract::assertDto(name: 'EvidenceContextRequest', value: get_object_vars($this));
    }
}
