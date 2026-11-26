<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Fusion;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Retrieval\Rerank\ConstrainedRerank;
use KnowKit\Domain as D;
use KnowKit\Port\LexicalIndex;
use KnowKit\Port\VectorIndex;

/** Reference discovery only. Results still require bounded selection and fresh disclosure. */
final readonly class HybridSearch
{
    public function __construct(
        private LexicalIndex $lexical,
        private ReciprocalRankFusion $fusion,
        private ?VectorIndex $vector = null,
        private ?ConstrainedRerank $rerank = null,
    ) {}

    /** @return D\Result<D\CandidateList> */
    public function search(
        AccessContext $context,
        D\SearchCorpus $lexical,
        D\QueryBinding $query,
        D\SearchProfile $profile = new D\SearchProfile(),
        ?D\SearchCorpus $vector = null,
        int $topK = 64,
        ?\Closure $currentBinding = null,
    ): D\Result {
        try {
            $context->assertCurrent();
            $this->validate($context, $lexical, $vector, $query, $profile, $topK);
            $hits = $this->lexical->search($lexical->scope, $query->original, $topK);
            $lexical->assertList($hits, $topK);
            $matches = array_values(array_filter($hits->candidates,
                static fn (D\Candidate $hit): bool => $hit->score === null || $hit->score > 0));

            return $this->enhance($context, $lexical, $vector, $query, $profile, $matches, $topK, $currentBinding);
        } catch (D\DomainFailure $failure) {
            return D\Result::failure($failure);
        } catch (\Throwable) {
            return D\Result::failure(new D\DomainFailure(D\Outcome::Unavailable, 'retrieval_unavailable'));
        }
    }

    private function validate(
        AccessContext $context,
        D\SearchCorpus $lexical,
        ?D\SearchCorpus $vector,
        D\QueryBinding $query,
        D\SearchProfile $profile,
        int $topK,
    ): void {
        if ($topK < 1 || $topK > 512 || $query->profile->id !== $profile->id) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'profile_invalid');
        }
        $lexical->assertBinding($context->scope());
        $lexical->assertSpace(null);
        $lexical->assertProfile($profile->lexicalBackend, 'lexical');
        $expectedSpace = $profile->space?->digest();
        if (
            ($query->spaceFingerprint === null) !== ($expectedSpace === null)
            || ($query->spaceFingerprint !== null && $expectedSpace !== null && !$query->spaceFingerprint->equals($expectedSpace))
        ) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_mismatch');
        }
        if ($profile->space === null) {
            return;
        }
        // Missing pinned inventory is never an optional provider outage.
        if ($vector === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_mismatch');
        }
        $vector->assertBinding($context->scope());
        $vector->assertSpace($profile->space->digest());
        $vector->assertProfile('pgvector-l2-v1', 'vector');
        $lexical->assertCompanion($vector);
    }

    /**
     * @param list<D\Candidate> $matches
     * @return D\Result<D\CandidateList>
     */
    private function enhance(
        AccessContext $context,
        D\SearchCorpus $lexical,
        ?D\SearchCorpus $vector,
        D\QueryBinding $query,
        D\SearchProfile $profile,
        array $matches,
        int $topK,
        ?\Closure $currentBinding,
    ): D\Result {
        $rankings = [$matches];
        $corpus = $lexical;
        $stage = 'vector';
        try {
            if ($profile->space !== null && $vector !== null) {
                $rankings[] = $this->vectorRanking($vector, $query, $profile->space, $topK);
                $corpus = $vector;
            }
            $candidates = $this->fusion->fuse($corpus, $rankings);
            $stage = 'rerank';
            if ($profile->rerank !== null && $candidates->candidates !== []) {
                $rerank = $this->rerank ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'rerank_unavailable');
                if ($currentBinding === null) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'policy_binding_required');
                }
                $candidates = $rerank->apply($context, $corpus, $candidates, $query, $profile->rerank, $currentBinding);
            }
        } catch (D\DomainFailure $failure) {
            if (!$profile->mayDegrade($failure, $profile->rerankRequired)) {
                throw $failure;
            }
            $context->assertCurrent();

            return $this->result($this->fusion->fuse($lexical, [$matches]), $profile, true,
                [$stage . ':' . $failure->errorCode]);
        }
        $context->assertCurrent();

        return $this->result($candidates, $profile);
    }

    /** @return list<D\Candidate> */
    private function vectorRanking(D\SearchCorpus $corpus, D\QueryBinding $query, D\EmbeddingSpace $space, int $topK): array
    {
        $index = $this->vector ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'vector_unavailable');
        $hits = $index->search($corpus->scope, $query->original, $topK, $space);
        $corpus->assertList($hits, $topK);

        return $hits->candidates;
    }

    /**
     * @param list<string> $warnings
     * @return D\Result<D\CandidateList>
     */
    private function result(D\CandidateList $candidates, D\SearchProfile $profile, bool $degraded = false, array $warnings = []): D\Result
    {
        $effective = $degraded ? 'lexical-v1' : $profile->id;
        $warnings[] = 'lexical_backend:' . $profile->lexicalBackend;
        // Keep degradation metadata even when lexical contributes no candidates.
        if ($candidates->candidates === []) {
            return D\Result::empty($effective, $warnings, $degraded);
        }

        return D\Result::success($candidates, $degraded, $effective, $warnings);
    }
}
