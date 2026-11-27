<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Domain;

enum TrainingFormat: string
{
    case DistillPairsV1 = 'distill-pairs-v1';
    case GroundedSftV1 = 'sft-grounded-v1';
    case GrpoTasksV1 = 'grpo-tasks-v1';
}
