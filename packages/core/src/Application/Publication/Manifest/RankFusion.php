<?php
declare(strict_types=1);

namespace KnowKit\Application\Publication\Manifest;

use KnowKit\Domain as D;
use KnowKit\Domain\ManifestCandidate as Candidate;

/** Rank-only RRF, k=60. PostgreSQL FTS scores are never relabelled as BM25. */
final class RankFusion
{
    /**
     * @param list<list<D\Candidate>> $rankings
     * @return list<D\Candidate>
     */
    public static function fuse(Candidate $manifest, array $rankings): array
    {
        $scores = [];
        $refs = [];
        foreach ($rankings as $ranking) {
            $seen = [];
            $ranks = [];
            foreach ($ranking as $hit) {
                $manifest->assertCandidate($hit->ref);
                $id = $hit->ref->chunkId->value;
                if (isset($seen[$id]) || isset($ranks[$hit->rank])) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'ranking_invalid');
                }
                $seen[$id] = true;
                $ranks[$hit->rank] = true;
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (60 + $hit->rank);
                $refs[$id] = $hit->ref;
            }
        }
        $ids = array_keys($scores);
        usort($ids, static fn(string $a, string $b): int => ($scores[$b] <=> $scores[$a]) ?: strcmp($a, $b));
        $result = [];
        foreach ($ids as $id) {
            $result[] = new D\Candidate($refs[$id], count($result) + 1, $scores[$id]);
        }
        return $result;
    }
}
