<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('quality:retrieval')]
final class MetricsTest extends TestCase
{
    private function golden(): array
    {
        $corpus = new Corpus(dirname(__DIR__, 2) . '/tests/fixtures/evaluation/manifest.v1.json');
        $case = $corpus->questions[0];
        $fragments = [];
        foreach (['exact', 'page-coordinate'] as $index => $name) {
            $fragments[] = ['citation' => $case['relevant'][$index]['evidence'], 'text' => $corpus->document($name)['text']];
        }

        return [$case, ['outcome' => 'success', 'fragments' => $fragments]];
    }

    public function testHandComputedRankDiscountDuplicatesAndCutoff(): void
    {
        [$case, $actual] = $this->golden();
        self::assertEquals(1.0, Metrics::score($case, $actual)['fragmentRecallAt10']);
        self::assertSame(1.0, Metrics::score($case, $actual)['ndcgAt10']);
        $duplicate = $actual;
        $duplicate['fragments'] = [$actual['fragments'][0], $actual['fragments'][0]];
        $score = Metrics::score($case, $duplicate);
        self::assertSame(0.5, $score['fragmentRecallAt10']);
        self::assertEqualsWithDelta(1 / (1 + 1 / log(3, 2)), $score['ndcgAt10'], 1e-12);
        $late = $actual;
        $late['fragments'] = [...array_fill(0, 10, $actual['fragments'][0]), $actual['fragments'][1]];
        self::assertSame(0.5, Metrics::score($case, $late)['fragmentRecallAt10']);
        $discounted = $actual;
        $discounted['fragments'] = [$actual['fragments'][0], $actual['fragments'][0], $actual['fragments'][1]];
        self::assertEqualsWithDelta((1 + 1 / log(4, 2)) / (1 + 1 / log(3, 2)),
            Metrics::score($case, $discounted)['ndcgAt10'], 1e-12);
    }

    public function testNormalizedTextWrongRevisionAndForgedLocatorsNeverProveProvenance(): void
    {
        [$case, $actual] = $this->golden();
        foreach (['sourceVersionId', 'documentRevisionId', 'irDigest', 'sourceDigest', 'documentId', 'tenantId'] as $field) {
            $forged = $actual;
            $forged['fragments'][0]['citation'][$field] = 'forged';
            self::assertSame(1, Metrics::score($case, $forged)['provenanceViolations'], $field);
            self::assertSame(0.5, Metrics::score($case, $forged)['fragmentRecallAt10'], $field);
        }
        $actual['fragments'][0]['text'] = mb_strtolower($actual['fragments'][0]['text']);
        self::assertSame(1, Metrics::score($case, $actual)['provenanceViolations']);
        [$case, $actual] = $this->golden();
        $actual['fragments'][1]['citation']['spans'][0]['byteStart'] = 1;
        self::assertSame(1, Metrics::score($case, $actual)['provenanceViolations']);
        $actual['fragments'][1]['text'] = "\xD0";
        self::assertSame(0.5, Metrics::score($case, $actual)['utf8Validity']);
    }

    public function testEmptyErrorsAndForbiddenCitationsAreSeparate(): void
    {
        [$case, $actual] = $this->golden();
        $case['relevant'] = [];
        $case['expectedOutcome'] = 'empty';
        $empty = ['outcome' => 'empty', 'fragments' => []];
        self::assertSame(1, Metrics::score($case, $empty)['refusalCorrectness']);
        $empty['outcome'] = 'unavailable';
        self::assertSame(0, Metrics::score($case, $empty)['refusalCorrectness']);
        self::assertSame(1, Metrics::score($case, $empty)['errors']);
        foreach (['revoke' => 'postRevokeViolations', 'missing-object' => 'unavailableCitations', 'denied' => 'deniedCitations'] as $scenario => $metric) {
            $case['scenario'] = $scenario;
            self::assertSame(2, Metrics::score($case, $actual)[$metric]);
        }
        $rows = [Metrics::score($case, $empty), Metrics::score($case, $actual)];
        self::assertSame(1, Metrics::aggregate($rows)['errors'] / 2);
    }

    public function testCanonicalHardNegativeCannotHideBehindPerfectRecallAndRanking(): void
    {
        [$case, $actual] = $this->golden();
        $case['hardNegatives'] = ['canonical-neighbor'];
        $universe = $case['relevant'];
        $universe[0]['name'] = 'intended';
        $universe[1]['name'] = 'canonical-neighbor';
        $score = Metrics::score($case, $actual, $universe);
        self::assertEquals(1.0, $score['fragmentRecallAt10']);
        self::assertSame(1.0, $score['ndcgAt10']);
        self::assertSame(0, $score['provenanceViolations']);
        self::assertSame(1, $score['hardNegativeHits']);
        self::assertSame(1, $score['errors']);
    }

    public function testMissingTableUnitAndNoteReduceRetention(): void
    {
        $corpus = new Corpus(dirname(__DIR__, 2) . '/tests/fixtures/evaluation/manifest.v1.json');
        $case = array_values(array_filter($corpus->questions, static fn (array $row): bool => $row['id'] === 'table-units'))[0];
        $fragment = ['citation' => $case['relevant'][0]['evidence'], 'text' => '| Specimen | Mass | basalt | 12 |'];
        $score = Metrics::score($case, ['outcome' => 'success', 'fragments' => [$fragment]]);
        self::assertSame(0.4, $score['tableRetention']);
        self::assertSame(1, $score['provenanceViolations']);
    }

    public function testEveryApprovedThresholdAndEmptyMeasurementsRejectQualification(): void
    {
        $summary = ['fragmentRecallAt10' => 1.0];
        foreach (Evaluator::THRESHOLDS as $key => $threshold) {
            $summary[$key] = $threshold;
        }
        $report = ['datasetSize' => 1, 'executionCount' => 2, 'summary' => $summary];
        self::assertTrue(Evaluator::qualifies($report));
        foreach (Evaluator::THRESHOLDS as $key => $threshold) {
            $bad = $report;
            $key = $key === 'recallAt10' ? 'fragmentRecallAt10' : $key;
            $bad['summary'][$key] = $threshold === 0 ? 1 : $threshold - 0.01;
            self::assertFalse(Evaluator::qualifies($bad), $key);
        }
        $report['executionCount'] = 0;
        self::assertFalse(Evaluator::qualifies($report));
        self::assertSame(3.0, Evaluator::percentile([3, 1, 2], 0.95));
    }
}
