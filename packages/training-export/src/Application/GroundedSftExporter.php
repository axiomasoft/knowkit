<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Application;

use KnowKit\Domain\{DomainFailure, Outcome};
use KnowKit\Domain\Contract\Jcs;
use KnowKit\TrainingExport\Domain\TrainingTraceRecord;
use KnowKit\TrainingExport\Rewards\InvariantComplianceVerifier;

final readonly class GroundedSftExporter
{
    public function __construct(
        private InvariantComplianceVerifier $invariants = new InvariantComplianceVerifier(),
    ) {
    }

    /** @return array<string, mixed> */
    public function exportRecord(TrainingTraceRecord $trace): array
    {
        if (!$trace->hostOutcomeVerified) {
            throw new DomainFailure(Outcome::Denied, 'host_outcome_unverified');
        }

        if ($trace->messages === []) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_sft_trace');
        }

        if ($trace->layerStack !== null && $trace->lockedInvariants !== []) {
            $compliance = $this->invariants->evaluate($trace->layerStack, $trace->lockedInvariants, $trace->lockedInvariants);
            if ($compliance < 0) {
                throw new DomainFailure(Outcome::Denied, 'locked_invariant_violated');
            }
        }

        $resolvedCitationIds = [];
        foreach ($trace->citations as $aliasOrId) {
            $ref = $trace->citationAliasMap->resolve($aliasOrId);
            if ($ref !== null) {
                $resolvedCitationIds[] = $ref->id()->value;
                continue;
            }
            $alias = null;
            foreach ($trace->citationAliasMap->all() as $candidateRef) {
                if ($candidateRef->id()->value === $aliasOrId) {
                    $alias = $candidateRef->id()->value;
                    break;
                }
            }
            if ($alias === null) {
                throw new DomainFailure(Outcome::Denied, 'invalid_citation');
            }
            $resolvedCitationIds[] = $alias;
        }

        foreach ($trace->messages as $message) {
            if ($message['role'] === 'assistant' && preg_match_all('/\[(#\d+)\]/', $message['content'], $matches) > 0) {
                foreach ($matches[1] as $alias) {
                    $ref = $trace->citationAliasMap->resolve($alias);
                    if ($ref === null) {
                        throw new DomainFailure(Outcome::Denied, 'invalid_citation');
                    }
                    $resolvedCitationIds[] = $ref->id()->value;
                }
            }
        }

        $resolvedCitationIds = array_values(array_unique($resolvedCitationIds));
        if ($resolvedCitationIds === []) {
            throw new DomainFailure(Outcome::Denied, 'invalid_citation');
        }
        sort($resolvedCitationIds, SORT_STRING);

        $evidenceDigests = [];
        $fragments = $trace->evidenceSet->toWire()->get('fragments');
        if (is_array($fragments)) {
            foreach ($fragments as $fragment) {
                $digest = $fragment->get('fragmentDigest');
                if (is_string($digest)) {
                    $evidenceDigests[] = $digest;
                }
            }
        }
        sort($evidenceDigests, SORT_STRING);

        return [
            'citationAliasMapDigest' => $trace->citationAliasMap->digest()->value,
            'citations' => $resolvedCitationIds,
            'evidenceDigests' => $evidenceDigests,
            'layerStackDigest' => $trace->layerStackDigest->value,
            'messages' => $trace->messages,
            'traceId' => $trace->traceId->value,
        ];
    }

    public function exportJsonlLine(TrainingTraceRecord $trace): string
    {
        return Jcs::canonicalize($this->exportRecord($trace));
    }
}
