<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

use RuntimeException;

final readonly class EffectiveProfile
{
    public function __construct(
        public string $environment,
        public string $name = 'compact',
    ) {
        if ($environment !== 'test' || $name !== 'compact') {
            throw new RuntimeException('Fixture requires EffectiveProfile.environment=test and qualified compact profile');
        }
    }

    public function fragmentLimit(EvidenceContextRequest $request): int
    {
        return min(4, $request->maxFragments, 8);
    }

    public function byteLimit(EvidenceContextRequest $request): int
    {
        return min(4096, $request->maxBytes, 8192);
    }
}
