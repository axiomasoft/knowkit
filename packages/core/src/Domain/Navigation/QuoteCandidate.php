<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain\TextLocator;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class QuoteCandidate
{
    public function __construct(
        public TextLocator $span,
        public string $matchKind,
        public int $candidateCount,
    ) {
        if ($candidateCount < 1 || !in_array($matchKind, ['exact', 'normalized-candidate-v1', 'unicode-nfd-intl-v1'], true)) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_location');
        }
    }
}
