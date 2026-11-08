<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Sha256;

final readonly class InvocationPins
{
    public function __construct(
        public ResolvedModelBinding $binding,
        public ProfileRef $retrievalProfile,
        public Sha256 $originalRequestDigest,
    ) {
    }
}
