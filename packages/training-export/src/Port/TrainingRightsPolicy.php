<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Port;

use KnowKit\Domain\{ExactSourceRef, Scope};
use KnowKit\TrainingExport\Domain\TrainingRightsDecision;

interface TrainingRightsPolicy
{
    public function decide(Scope $scope, ExactSourceRef $source): TrainingRightsDecision;
}
