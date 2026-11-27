<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Graph;

use KnowKit\Domain as D;
use KnowKit\Port\ConceptEdgeIndex;

/**
 * Bounded concept-graph expansion over AuthorizedSearchScope.
 * Every discovered node is verified against $scope and $policy->effectiveDenySet
 * BEFORE entering the resulting CandidateList.
 */
final readonly class GraphExpander
{
    /** @param (\Closure(D\ChunkId): ?D\ChunkRef)|null $chunkRefResolver */
    public function __construct(
        private ?\Closure $chunkRefResolver = null,
    ) {}

    public function expand(
        D\AuthorizedSearchScope $scope,
        D\CandidateList $seed,
        ConceptEdgeIndex $edges,
        D\GraphExpansionPolicy $policy,
    ): D\CandidateList {
        if (!$seed->scope->equals($scope)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }

        $denyLookup = [];
        foreach ($policy->effectiveDenySet as $selector) {
            $denyLookup[$selector->value] = true;
        }

        $baseCandidates = [];
        $visited = [];
        $frontier = [];
        foreach ($seed->candidates as $candidate) {
            $scope->assertCandidate($candidate->ref);
            if ($this->isDenied($candidate->ref, $denyLookup)) {
                continue;
            }
            $baseCandidates[] = new D\Candidate($candidate->ref, count($baseCandidates) + 1, $candidate->score);
            $visited[$candidate->ref->chunkId->value] = true;
            $frontier[] = $candidate->ref->chunkId;
        }

        if (
            $policy->maxHops === 0
            || $policy->maxExpandedNodes === 0
            || $policy->allowedRelations === []
            || $frontier === []
        ) {
            return new D\CandidateList($scope, $baseCandidates);
        }

        $expanded = [];
        $fetchLimit = min(1024, max(32, $policy->maxExpandedNodes * 4));

        for ($hop = 1; $hop <= $policy->maxHops && $frontier !== [] && count($expanded) < $policy->maxExpandedNodes; $hop++) {
            $edgeRows = $edges->neighbors(
                $scope->tenantId,
                $scope->kbId,
                $scope->snapshotId,
                $frontier,
                $policy->allowedRelations,
                $fetchLimit,
            );
            $nextFrontier = [];

            foreach ($edgeRows as $edge) {
                if (!in_array($edge->relation, $policy->allowedRelations, true)) {
                    continue;
                }
                $targetId = $edge->toChunkId;
                if (isset($visited[$targetId->value])) {
                    continue;
                }
                $ref = $this->resolveChunkRef($targetId);
                if ($ref === null) {
                    continue;
                }
                if (!$this->isAuthorized($scope, $ref) || $this->isDenied($ref, $denyLookup)) {
                    continue;
                }

                $visited[$targetId->value] = true;
                $expanded[] = new D\Candidate(
                    $ref,
                    count($baseCandidates) + count($expanded) + 1,
                    (float) $edge->weightBps / 10000.0,
                );
                $nextFrontier[] = $targetId;

                if (count($expanded) >= $policy->maxExpandedNodes) {
                    break;
                }
            }

            $frontier = $nextFrontier;
        }

        return new D\CandidateList($scope, [...$baseCandidates, ...$expanded]);
    }

    private function resolveChunkRef(D\ChunkId $chunkId): ?D\ChunkRef
    {
        if ($this->chunkRefResolver === null) {
            return null;
        }
        $ref = ($this->chunkRefResolver)($chunkId);

        return $ref instanceof D\ChunkRef ? $ref : null;
    }

    private function isAuthorized(D\AuthorizedSearchScope $scope, D\ChunkRef $ref): bool
    {
        try {
            $scope->assertCandidate($ref);

            return true;
        } catch (D\DomainFailure) {
            return false;
        }
    }

    /** @param array<string, true> $denyLookup */
    private function isDenied(D\ChunkRef $ref, array $denyLookup): bool
    {
        if ($denyLookup === []) {
            return false;
        }

        $keys = [
            $ref->chunkId->value,
            'chunk:' . $ref->chunkId->value,
            $ref->revisionRef->sourceRef->sourceVersionId->value,
            'source_version:' . $ref->revisionRef->sourceRef->sourceVersionId->value,
            $ref->revisionRef->sourceRef->sourceId->value,
            'source:' . $ref->revisionRef->sourceRef->sourceId->value,
            $ref->revisionRef->documentRevisionId->value,
            'revision:' . $ref->revisionRef->documentRevisionId->value,
            $ref->chunkSetId->value,
            'chunk_set:' . $ref->chunkSetId->value,
        ];

        foreach ($keys as $key) {
            if (isset($denyLookup[$key])) {
                return true;
            }
        }

        return false;
    }
}
