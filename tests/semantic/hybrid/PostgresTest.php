<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/lexical/bootstrap.php';

use KnowKit\Application\Retrieval\Fusion\HybridSearch;
use KnowKit\Application\Retrieval\Fusion\ReciprocalRankFusion;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Lexical\LexicalFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class PostgresTest extends TestCase
{
    public function testPostgresNoLexicalMatchContributesNoPseudoHit(): void
    {
        LexicalFixture::run(function (LexicalFixture $postgres): void {
            $f = new Fixture();
            $profile = new D\SearchProfile('hybrid-v1', Fixture::space());
            $f->vectorCandidates = [$f->hit('neighbor', 1, 999999)];
            $service = new HybridSearch($postgres->index, new ReciprocalRankFusion(), $f);
            $result = $service->search($f->host->context(), $f->lexical, $f->query($profile, '存在しない語彙xyz'), $profile, $f->vector);
            self::assertSame(D\Outcome::Success, $result->outcome);
            self::assertCount(1, $result->data->candidates);
            self::assertSame($f->hit('neighbor')->ref, $result->data->candidates[0]->ref);
            self::assertEqualsWithDelta(0.01639344262295082, $result->data->candidates[0]->score, 1e-15);
            $f->vectorCandidates = [];
            self::assertSame(D\Outcome::Empty, $service->search($f->host->context(), $f->lexical,
                $f->query($profile, '存在しない語彙xyz'), $profile, $f->vector)->outcome);
        });
    }

    public function testPostgresExactIdentifierOutranksSemanticNoiseThroughRanks(): void
    {
        LexicalFixture::run(function (LexicalFixture $postgres): void {
            $f = new Fixture();
            $profile = new D\SearchProfile('hybrid-v1', Fixture::space());
            $f->vectorCandidates = [$f->hit('neighbor', 1, 999999), $f->hit('exact', 2, 1)];
            $service = new HybridSearch($postgres->index, new ReciprocalRankFusion(), $f);
            $result = $service->search($f->host->context(), $f->lexical, $f->query($profile), $profile, $f->vector);
            self::assertSame(D\Outcome::Success, $result->outcome);
            self::assertSame($f->hit('exact')->ref, $result->data->candidates[0]->ref);
            self::assertSame($f->hit('neighbor')->ref, $result->data->candidates[1]->ref);
            self::assertContains('lexical_backend:postgres-fts-simple-v1', $result->warnings);
            self::assertStringNotContainsString('BM25', implode(',', $result->warnings));
        });
    }
}
