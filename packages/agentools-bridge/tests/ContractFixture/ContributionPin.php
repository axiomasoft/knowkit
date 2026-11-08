<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

final readonly class ContributionPin
{
    public function __construct(
        public string $id,
        public string $version,
        public string $contractDigest,
        public string $configDigest,
    ) {
        Contract::assertDto(name: 'ContributionPin', value: get_object_vars($this));
    }

    public function digest(): string
    {
        return Contract::digest(get_object_vars($this));
    }
}
