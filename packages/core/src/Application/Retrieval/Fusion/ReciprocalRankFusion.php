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
        $contributions = [];
        $refs = [];
        foreach ($rankings as $ranking) {
            $unique = $this->unique($corpus, $ranking);
            foreach ($unique as $id => $candidate) {
                $contributions[$id][] = $candidate->rank;
                $refs[$id] = $candidate->ref;
            }
        }
        $scores = [];
        foreach ($contributions as $id => $ranks) {
            sort($ranks, SORT_NUMERIC);
            $scores[$id] = array_sum(array_map(static fn (int $rank): float => 1.0 / (60 + $rank), $ranks));
        }
        $ids = array_keys($scores);
        usort($ids, static fn (string $a, string $b): int => ($scores[$b] <=> $scores[$a]) ?: strcmp($a, $b));

        return $this->withoutOverlap($corpus, $ids, $refs, $scores);
    }

    /** @param list<D\Candidate> $ranking @return array<string, D\Candidate> */
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

    /** @param list<string> $ids @param array<string, D\ChunkRef> $refs @param array<string, float> $scores */
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
