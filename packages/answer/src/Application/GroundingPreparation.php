<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain\GroundingEvidence;
use KnowKit\Answer\Domain\TransportRequest;

final readonly class GroundingPreparation
{
    public function __construct(
        public GroundingPrompt $prompt,
        public GroundingEvidence $evidence,
        public TransportRequest $request,
    ) {
    }
}
