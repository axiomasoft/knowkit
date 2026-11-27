<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

enum MergeStrategy: string
{
    case ImmutableSeal = 'immutable_seal';
    case GuardedExtend = 'guarded_extend';
    case OverrideAllowed = 'override_allowed';
    case WeightedMix = 'weighted_mix';
    case ExclusionDeny = 'exclusion_deny';
}
