<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Application;

use KnowKit\Domain\{DomainFailure, Outcome};
use KnowKit\Domain\Contract\Jcs;
use KnowKit\TrainingExport\Domain\TrainingTraceRecord;
use KnowKit\TrainingExport\Rewards\{GroundingRewardVerifier, InvariantComplianceVerifier};

final readonly class GrpoTaskExporter
{
    /** @return array<string, mixed> */
    public function exportRecord(TrainingTraceRecord $trace): array
    {
        if ($trace->promptEnvelopeDigest === null) {
            throw new DomainFailure(Outcome::Incompatible, 'missing_prompt_envelope');
        }

        $disclosedEvidenceDigests = [];
        $fragments = $trace->evidenceSet->toWire()->get('fragments');
        if (is_array($fragments)) {
            foreach ($fragments as $fragment) {
                $digest = $fragment->get('fragmentDigest');
                if (is_string($digest)) {
                    $disclosedEvidenceDigests[] = $digest;
                }
            }
        }
        sort($disclosedEvidenceDigests, SORT_STRING);

        $lockedInvariants = $trace->lockedInvariants;
        ksort($lockedInvariants, SORT_STRING);

        return [
            'disclosedEvidenceDigests' => $disclosedEvidenceDigests,
            'lockedInvariants' => $lockedInvariants,
            'promptEnvelopeDigest' => $trace->promptEnvelopeDigest->value,
            'traceId' => $trace->traceId->value,
            'verifierSpecs' => [
                [
                    'hallucinationPenaltyPermille' => GroundingRewardVerifier::HALLUCINATION_PENALTY_PERMILLE,
                    'id' => 'grounding',
                    'passRewardPermille' => GroundingRewardVerifier::PASS_PERMILLE,
                ],
                [
                    'id' => 'invariants',
                    'passRewardPermille' => InvariantComplianceVerifier::PASS_PERMILLE,
                    'violationPenaltyPermille' => InvariantComplianceVerifier::VIOLATION_PENALTY_PERMILLE,
                ],
            ],
        ];
    }

    public function exportJsonlLine(TrainingTraceRecord $trace): string
    {
        return Jcs::canonicalize($this->exportRecord($trace));
    }
}
