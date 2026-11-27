<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Fusion;

use KnowKit\Domain as D;

/** Adapted from AgenTools RRF. Explicit ranks replace donor raw-score sorting. */
final class ReciprocalRankFusion
{
    /** @param list<list<D\Candidate>> $rankings */
    public function fuse(D\SearchCorpus $corpus, array $rankings): D\CandidateList
    {
        $weighted = array_map(
            static fn (array $ranking): D\WeightedRanking => new D\WeightedRanking($ranking, 10000),
            $rankings,
        );

        return $this->fuseWeighted($corpus, $weighted);
    }

    /** @param list<D\WeightedRanking> $weightedRankings */
    public function fuseWeighted(D\SearchCorpus $corpus, array $weightedRankings, int $k = 60): D\CandidateList
    {
        if ($k < 1) {
            throw new \InvalidArgumentException('k must be positive');
        }
        D\ValueGuard::objects($weightedRankings, D\WeightedRanking::class, 256);

        /** @var array<string, list<array{int, int}>> $contributions */
        $contributions = [];
        /** @var array<string, D\ChunkRef> $refs */
        $refs = [];
        foreach ($weightedRankings as $weightedRanking) {
            $unique = $this->unique($corpus, $weightedRanking->candidates);
            foreach ($unique as $id => $candidate) {
                $contributions[$id][] = [$weightedRanking->weightBps, $candidate->rank];
                $refs[$id] = $candidate->ref;
            }
        }

        // Exact scaled integer comparison (0 float nondeterminism) with deterministic chunkId tie-break.
        $scaledScores = [];
        $displayScores = [];
        foreach ($contributions as $id => $items) {
            usort($items, static fn (array $a, array $b): int => ($a[1] <=> $b[1]) ?: ($a[0] <=> $b[0]));
            $scaled = 0;
            $display = 0.0;
            foreach ($items as [$weightBps, $rank]) {
                $scaled += intdiv($weightBps * 1_000_000_000, $k + $rank);
                $display += ($weightBps / 10000.0) / ($k + $rank);
            }
            $scaledScores[$id] = $scaled;
            $displayScores[$id] = $display;
        }

        $ids = array_keys($scaledScores);
        usort($ids, static fn (string $a, string $b): int => ($scaledScores[$b] <=> $scaledScores[$a]) ?: strcmp($a, $b));

        return $this->withoutOverlap($corpus, $ids, $refs, $displayScores);
    }

    /**
     * @param list<D\Candidate> $ranking
     * @return array<string, D\Candidate>
     */
    private function unique(D\SearchCorpus $corpus, array $ranking): array
    {
        D\ValueGuard::objects($ranking, D\Candidate::class, 512);
        $unique = [];
        foreach ($ranking as $candidate) {
            $corpus->scope->assertCandidate($candidate->ref);
            $corpus->inventory->manifest->assertCandidate($candidate->ref);
            $id = $candidate->ref->chunkId->value;
            if (!isset($unique[$id]) || $candidate->rank < $unique[$id]->rank) {
                $unique[$id] = $candidate;
            }
        }

        return $unique;
    }

    /**
     * @param list<string> $ids
     * @param array<string, D\ChunkRef> $refs
     * @param array<string, float> $scores
     */
    private function withoutOverlap(D\SearchCorpus $corpus, array $ids, array $refs, array $scores): D\CandidateList
    {
        $units = [];
        $result = [];
        foreach ($ids as $id) {
            $unit = $corpus->inventory->unit($refs[$id]);
            foreach ($units as $selected) {
                if ($unit->overlaps($selected)) {
                    continue 2;
                }
            }
            $units[] = $unit;
            $result[] = new D\Candidate($refs[$id], count($result) + 1, $scores[$id]);
        }

        return new D\CandidateList($corpus->scope, $result);
    }
}
