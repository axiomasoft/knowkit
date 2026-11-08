<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

final readonly class ContributionReceipt
{
    public function __construct(
        public string $schema,
        public string $id,
        public string $pinDigest,
        public string $hostScopeHash,
        public array $selectionRefs,
        public array $evidenceSetIds,
        public array $sourceEpochs,
        public string $expiresAt,
        public array $modelViewDigests,
    ) {
        Contract::assertDto(name: 'ContributionReceipt', value: get_object_vars($this));
    }
}
