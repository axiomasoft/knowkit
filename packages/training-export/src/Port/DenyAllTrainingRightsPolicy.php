<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Port;

use KnowKit\Domain\{ExactSourceRef, Scope};
use KnowKit\TrainingExport\Domain\TrainingRightsDecision;

/** Fail-closed default: denies all training exports unless explicitly permitted. */
final readonly class DenyAllTrainingRightsPolicy implements TrainingRightsPolicy
{
    public function decide(Scope $scope, ExactSourceRef $source): TrainingRightsDecision
    {
        return TrainingRightsDecision::denied('training_rights_denied');
    }
}
