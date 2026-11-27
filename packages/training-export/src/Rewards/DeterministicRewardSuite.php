<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Rewards;

use KnowKit\TrainingExport\Domain\TrainingTraceRecord;

final readonly class DeterministicRewardSuite
{
    public function __construct(
        private GroundingRewardVerifier $grounding = new GroundingRewardVerifier(),
        private InvariantComplianceVerifier $invariants = new InvariantComplianceVerifier(),
        private ?HostDomainRewardHook $hostHook = null,
    ) {
    }

    /**
     * @param list<string> $citations
     * @param array<string, bool|int|string> $observedInvariants
     */
    public function evaluate(
        TrainingTraceRecord $trace,
        string $candidateResponse,
        array $citations = [],
        array $observedInvariants = [],
        bool $requireGrounding = true,
    ): RewardScore {
        $groundingScore = $this->grounding->evaluate(
            $trace->citationAliasMap,
            $candidateResponse,
            $citations,
            $requireGrounding,
        );

        $invariantScore = $this->invariants->evaluate(
            $trace->layerStack,
            $observedInvariants,
            $trace->lockedInvariants,
        );

        $breakdown = [
            'grounding' => $groundingScore,
            'invariants' => $invariantScore,
        ];
        $total = $groundingScore + $invariantScore;

        if ($this->hostHook !== null) {
            $hostScore = RewardScore::clamp($this->hostHook->evaluate($trace, $candidateResponse));
            $breakdown[$this->hostHook->id()] = $hostScore;
            $total += $hostScore;
        }

        return new RewardScore($total, $breakdown);
    }
}
