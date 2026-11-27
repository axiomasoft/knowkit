<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Fusion;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain as D;
use KnowKit\Domain\Layers\{DeliveryMode, LayerNode, ResolvedLayerStack};
use KnowKit\Port\{LexicalIndex, VectorIndex};

/**
 * Layer-aware retrieval across hybrid_retrievable layers (snapshot and catalog_mount).
 * Enforces stack effectiveDenySelectors on AuthorizedSearchScope BEFORE backend queries,
 * then fuses per-layer rankings using each layer's budget.weightBps via fuseWeighted().
 */
final readonly class LayeredRetriever
{
    /**
     * @param \Closure(LayerNode, list<D\LocalId>): ?D\SearchCorpus $corpusResolver
     */
    public function __construct(
        private LexicalIndex $lexical,
        private ReciprocalRankFusion $fusion,
        private \Closure $corpusResolver,
        private ?VectorIndex $vector = null,
        private ?D\EmbeddingSpace $vectorSpace = null,
    ) {}

    /** @return D\Result<D\CandidateList> */
    public function search(
        AccessContext $context,
        ResolvedLayerStack $stack,
        D\QueryBinding $query,
        int $topK = 64,
    ): D\Result {
        try {
            $context->assertCurrent();
            if ($topK < 1 || $topK > 512) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'profile_invalid');
            }
            if (!$stack->tenantId->equals($context->scope()->tenantId)) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }

            $denyLookup = $this->denyLookup($stack->effectiveDenySelectors);
            $weightedRankings = [];
            $primaryCorpus = null;

            foreach ($stack->nodes as $node) {
                if ($node->deliveryMode !== DeliveryMode::HybridRetrievable) {
                    continue;
                }
                if ($node->backing->kind !== 'snapshot' && $node->backing->kind !== 'catalog_mount') {
                    continue;
                }

                $corpus = ($this->corpusResolver)($node, $stack->effectiveDenySelectors);
                if ($corpus === null) {
                    continue;
                }

                $this->assertScopeExcludesDenied($corpus->scope, $denyLookup);
                $primaryCorpus ??= $corpus;

                $lexicalHits = $this->lexical->search($corpus->scope, $query->original, $topK);
                $corpus->assertList($lexicalHits, $topK);
                $lexicalMatches = $this->filterCandidates($lexicalHits->candidates, $denyLookup);
                if ($lexicalMatches !== []) {
                    $weightedRankings[] = new D\WeightedRanking($lexicalMatches, $node->budget->weightBps);
                }

                if ($this->vector !== null && $this->vectorSpace !== null) {
                    $vectorHits = $this->vector->search($corpus->scope, $query->original, $topK, $this->vectorSpace);
                    $corpus->assertList($vectorHits, $topK);
                    $vectorMatches = $this->filterCandidates($vectorHits->candidates, $denyLookup);
                    if ($vectorMatches !== []) {
                        $weightedRankings[] = new D\WeightedRanking($vectorMatches, $node->budget->weightBps);
                    }
                }
            }

            $context->assertCurrent();
            if ($primaryCorpus === null || $weightedRankings === []) {
                return D\Result::empty('layered-v1');
            }

            $fused = $this->fusion->fuseWeighted($primaryCorpus, $weightedRankings);
            if ($fused->candidates === []) {
                return D\Result::empty('layered-v1');
            }

            return D\Result::success($fused, false, 'layered-v1');
        } catch (D\DomainFailure $failure) {
            return D\Result::failure($failure);
        } catch (\Throwable) {
            return D\Result::failure(new D\DomainFailure(D\Outcome::Unavailable, 'retrieval_unavailable'));
        }
    }

    /**
     * @param list<D\LocalId> $selectors
     * @return array<string, true>
     */
    private function denyLookup(array $selectors): array
    {
        $lookup = [];
        foreach ($selectors as $selector) {
            $lookup[$selector->value] = true;
        }

        return $lookup;
    }

    /** @param array<string, true> $denyLookup */
    private function assertScopeExcludesDenied(D\AuthorizedSearchScope $scope, array $denyLookup): void
    {
        if ($denyLookup === []) {
            return;
        }
        foreach ($scope->allowedSourceVersionIds as $versionId) {
            if (
                isset($denyLookup[$versionId->value])
                || isset($denyLookup['source_version:' . $versionId->value])
            ) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
        }
    }

    /**
     * @param list<D\Candidate> $candidates
     * @param array<string, true> $denyLookup
     * @return list<D\Candidate>
     */
    private function filterCandidates(array $candidates, array $denyLookup): array
    {
        $filtered = [];
        $rank = 1;
        foreach ($candidates as $candidate) {
            if ($candidate->score !== null && $candidate->score <= 0) {
                continue;
            }
            if ($this->isDenied($candidate->ref, $denyLookup)) {
                continue;
            }
            $filtered[] = new D\Candidate($candidate->ref, $rank, $candidate->score);
            $rank++;
        }

        return $filtered;
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
