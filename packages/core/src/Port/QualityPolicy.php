<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Coverage, CoveragePolicy, QualityDecision};

interface QualityPolicy
{
    public function evaluate(Coverage $coverage, CoveragePolicy $policy): QualityDecision;
}
