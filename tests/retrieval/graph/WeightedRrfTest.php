<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Graph;

require_once dirname(__DIR__, 2) . '/semantic/hybrid/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;
use KnowKit\Tests\Semantic\Hybrid\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:graph')]
final class WeightedRrfTest extends TestCase
{
    public function testWeightedRrfUsesIntegerScaledScoresAndRespectsWeights(): void
    {
        $f = new Fixture();
        $allowed = $f->hit('allowed', 1);
        $tie = $f->hit('tie', 2);

        $lowWeight = new D\WeightedRanking([$allowed, $tie], 2000);
        $highWeight = new D\WeightedRanking([$f->hit('tie', 1), $f->hit('allowed', 2)], 8000);

        $result = (new ReciprocalRankFusion())->fuseWeighted($f->lexical, [$lowWeight, $highWeight]);

        self::assertCount(2, $result->candidates);
        self::assertSame($tie->ref->chunkId->value, $result->candidates[0]->ref->chunkId->value);
        self::assertSame($allowed->ref->chunkId->value, $result->candidates[1]->ref->chunkId->value);
        self::assertGreaterThan($result->candidates[1]->score, $result->candidates[0]->score);
    }

    public function testWeightedRrfTieBreaksDeterministicallyByChunkId(): void
    {
        $f = new Fixture();
        $a = $f->hit('allowed', 1);
        $b = $f->hit('tie', 2);
        $a2 = $f->hit('allowed', 2);
        $b1 = $f->hit('tie', 1);

        $fusion = new ReciprocalRankFusion();
        $first = $fusion->fuseWeighted($f->lexical, [
            new D\WeightedRanking([$a, $b], 5000),
            new D\WeightedRanking([$b1, $a2], 5000),
        ]);
        $reversed = $fusion->fuseWeighted($f->lexical, [
            new D\WeightedRanking([$b1, $a2], 5000),
            new D\WeightedRanking([$b, $a], 5000),
        ]);

        self::assertEquals($first, $reversed);
        $ids = array_map(static fn (D\Candidate $c): string => $c->ref->chunkId->value, $first->candidates);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);
    }

    public function testFuseDelegatesToFuseWeightedAndPreservesOverlapGuard(): void
    {
        $host = new Host('overlap');
        $scope = $host->checks()->authorizeQuery(
            $host->context(),
            $host->inventory->snapshot,
            $host->inventory->generationId(),
            $host->eligible,
            $host->binding,
        );
        $corpus = new D\SearchCorpus($scope, $host->inventory);
        $chunks = $host->inventory->manifest->sets[0]->chunkSet->chunks;
        $ranked = array_map(
            static fn (D\Chunk $chunk, int $index): D\Candidate => new D\Candidate($chunk->ref, $index + 1),
            $chunks,
            array_keys($chunks),
        );

        $fusion = new ReciprocalRankFusion();
        $fused = $fusion->fuse($corpus, [$ranked]);
        $weighted = $fusion->fuseWeighted($corpus, [new D\WeightedRanking($ranked, 10000)]);

        self::assertEquals($weighted, $fused);
        self::assertCount(1, $fused->candidates);
    }

    public function testWeightedRankingRejectsOutOfBoundsWeightBps(): void
    {
        foreach ([0, -1, 10001] as $invalidWeight) {
            try {
                new D\WeightedRanking([], $invalidWeight);
                self::fail('Expected InvalidArgumentException for weightBps=' . $invalidWeight);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
