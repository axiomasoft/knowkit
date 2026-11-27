<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Rewards;

use KnowKit\Domain\Layers\ResolvedLayerStack;

final readonly class InvariantComplianceVerifier
{
    public const PASS_PERMILLE = 500;
    public const VIOLATION_PENALTY_PERMILLE = -3000;

    /**
     * @param array<string, bool|int|string> $observedInvariants
     * @param array<string, bool|int|string> $expectedLockedInvariants
     */
    public function evaluate(
        ?ResolvedLayerStack $stack,
        array $observedInvariants,
        array $expectedLockedInvariants = [],
    ): int {
        if ($stack !== null) {
            $nodeRules = [];
            foreach ($stack->nodes as $node) {
                foreach ($node->ruleValues as $key => $value) {
                    $nodeRules[$key] = $value;
                }
            }
            foreach (array_keys($stack->lockedKeyOwners) as $lockedKey) {
                if (!array_key_exists($lockedKey, $observedInvariants)) {
                    return self::VIOLATION_PENALTY_PERMILLE;
                }
                if (array_key_exists($lockedKey, $nodeRules) && $observedInvariants[$lockedKey] !== $nodeRules[$lockedKey]) {
                    return self::VIOLATION_PENALTY_PERMILLE;
                }
            }
        }

        foreach ($expectedLockedInvariants as $key => $expected) {
            if (!array_key_exists($key, $observedInvariants) || $observedInvariants[$key] !== $expected) {
                return self::VIOLATION_PENALTY_PERMILLE;
            }
        }

        return self::PASS_PERMILLE;
    }
}
