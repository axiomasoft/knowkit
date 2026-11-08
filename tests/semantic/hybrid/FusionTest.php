<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Selection\Fixture as Host;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class FusionTest extends TestCase
{
    public function testRanksIgnoreScoreScalesAndTiesUseChunkId(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('allowed', 1, 0.001), $f->hit('tie', 2, 0.000001)];
        $f->vectorCandidates = [$f->hit('tie', 1, 999999), $f->hit('allowed', 2, 80000)];
        $result = $f->run();
        self::assertSame(D\Outcome::Success, $result->outcome);
        self::assertSame('hybrid-v1', $result->effectiveProfile);
        self::assertContains('lexical_backend:postgres-fts-simple-v1', $result->warnings);
        self::assertFalse($result->degraded);
        $ids = array_map(static fn (D\Candidate $hit): string => $hit->ref->chunkId->value, $result->data->candidates);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids);
        $expected = json_decode(file_get_contents(Fixture::root() . '/tests/fixtures/retrieval/hybrid/corpus.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($result->data->candidates as $hit) {
            self::assertEqualsWithDelta($expected['rrfTieScore'], $hit->score, 1e-15);
        }
        self::assertSame(0, $f->rerankCalls);
    }

    public function testExactDuplicatesContributeOncePerChannelAndInputOrderIsIrrelevant(): void
    {
        $f = new Fixture();
        $fusion = new ReciprocalRankFusion();
        $rankings = [[$f->hit('allowed', 2), $f->hit('tie', 2), $f->hit('allowed', 1), $f->hit('allowed', 1)],
            [$f->hit('tie', 1), $f->hit('allowed', 2)]];
        $actual = $fusion->fuse($f->lexical, $rankings);
        $clean = $fusion->fuse($f->lexical, [[$f->hit('allowed', 1), $f->hit('tie', 2)], $rankings[1]]);
        self::assertEquals($clean, $actual);
        self::assertCount(2, $actual->candidates);
        self::assertEquals($actual, $fusion->fuse($f->lexical, array_reverse(array_map('array_reverse', $rankings))));
    }

    public function testOverlappingCanonicalSpansAreOmittedWithoutWidening(): void
    {
        $host = new Host('overlap');
        $scope = $host->checks()->authorizeQuery($host->context(), $host->inventory->snapshot,
            $host->inventory->generationId(), $host->eligible, $host->binding);
        $corpus = new D\SearchCorpus($scope, $host->inventory);
        $chunks = $host->inventory->manifest->sets[0]->chunkSet->chunks;
        self::assertGreaterThan(1, count($chunks));
        $ranked = array_map(static fn (D\Chunk $chunk, int $index): D\Candidate => new D\Candidate($chunk->ref, $index + 1), $chunks, array_keys($chunks));
        $result = (new ReciprocalRankFusion())->fuse($corpus, [$ranked]);
        self::assertCount(1, $result->candidates);
        self::assertSame($chunks[0]->ref, $result->candidates[0]->ref);
        self::assertSame($chunks[0]->spans, $corpus->inventory->unit($result->candidates[0]->ref)->chunk->spans);
    }

    public function testForgedAndWrongRevisionRefsAreRejectedEvenWhenTheyWouldBeOmitted(): void
    {
        $f = new Fixture();
        $ref = $f->hit('allowed')->ref;
        $r = $ref->revisionRef;
        $wrongRevision = new D\DocumentRevisionRef($r->sourceRef, new D\DocumentRevisionId(Host::id(999)), $r->irDigest, $r->parserSignature);
        $forgeries = [new D\ChunkRef($r, $ref->chunkSetId, new D\ChunkId(Host::id(999)), $ref->chunkDigest),
            new D\ChunkRef($wrongRevision, $ref->chunkSetId, $ref->chunkId, $ref->chunkDigest)];
        foreach ($forgeries as $forged) {
            $f->host->candidates = [new D\Candidate($forged, 1, 0)];
            $result = $f->run();
            self::assertSame(D\Outcome::Incompatible, $result->outcome);
            self::assertSame('candidate_not_in_manifest', $result->errorCode);
            self::assertNull($result->data);
        }
        self::assertSame(0, $f->vectorCalls);
    }

    public function testZeroLexicalOverlapContributesNothing(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('exact', 1, 0)];
        $f->vectorCandidates = [$f->hit('neighbor', 1, -0.5)];
        $result = $f->run();
        self::assertSame(D\Outcome::Success, $result->outcome);
        self::assertCount(1, $result->data->candidates);
        self::assertSame($f->hit('neighbor')->ref, $result->data->candidates[0]->ref);
        self::assertEqualsWithDelta(0.01639344262295082, $result->data->candidates[0]->score, 1e-15);
        $f->vectorCandidates = [];
        self::assertSame(D\Outcome::Empty, $f->run()->outcome);
    }

    public function testDefaultProfileCallsOnlyLexicalAndReturnsReferences(): void
    {
        $f = new Fixture();
        $f->host->candidates = [$f->hit('exact')];
        $service = new HybridSearch($f->host, new ReciprocalRankFusion(), $f, $f->reranker());
        $profile = new D\SearchProfile();
        $result = $service->search($f->host->context(), $f->lexical, $f->query($profile));
        self::assertSame(D\Outcome::Success, $result->outcome);
        self::assertSame('lexical-v1', $result->effectiveProfile);
        self::assertSame(0, $f->vectorCalls);
        self::assertSame(0, $f->rerankCalls);
        self::assertSame(0, $f->egressCalls);
        self::assertStringNotContainsString('uses foo_bar', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
