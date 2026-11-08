<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('quality:retrieval')]
final class BaselineTest extends TestCase
{
    public function testProductionBaselineQualifiesEveryMandatoryCaseWithoutSkippedBackend(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/.phpunit.cache/P5.4';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $command = [PHP_BINARY, 'tools/evaluate.php', '--corpus', 'tests/fixtures/evaluation/manifest.v1.json',
            '--profile', 'postgres-fts-simple-v1', '--output', $directory . '/baseline.json'];
        $result = (new Qa($root))->process($command);
        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $report = Corpus::json($directory . '/baseline.json');
        self::assertTrue($report['qualified'], json_encode($report['summary'], JSON_THROW_ON_ERROR));
        self::assertSame(18, $report['datasetSize']);
        self::assertCount(36, $report['cases']);
        self::assertSame(['R24-A01', 'R24-A02', 'DT-Q09', 'R24-A03'], array_column($report['auditCases'], 'id'));
        self::assertSame(['passed'], array_values(array_unique(array_column($report['auditCases'], 'status'))));
        self::assertSame(3, $report['auditCases'][3]['unsafeRewritesRejected']);
        $corpus = new Corpus($root . '/tests/fixtures/evaluation/manifest.v1.json');
        foreach ($corpus->questions as $case) {
            $executions = array_values(array_filter($report['cases'], static fn (array $row): bool => $row['id'] === $case['id']));
            self::assertCount(2, $executions, $case['id']);
            self::assertSame(['cold', 'warm'], array_column($executions, 'phase'));
            foreach ($executions as $actual) {
                self::assertSame($case['expectedOutcome'], $actual['outcome'], $case['id']);
                self::assertSame(0, $actual['metrics']['errors'], $case['id']);
                self::assertSame(0, $actual['metrics']['provenanceViolations'], $case['id']);
                self::assertSame(count($case['relevant']), count($actual['citations']), $case['id']);
                if ($case['relevant'] !== []) {
                    self::assertSame(1, $actual['metrics']['fragmentRecallAt10'], $case['id']);
                    self::assertSame(1, $actual['metrics']['locatorAccuracy'], $case['id']);
                }
            }
        }
        foreach (['language:ru', 'language:en', 'language:zh', 'type:table', 'type:page', 'phase:cold', 'phase:warm'] as $slice) {
            self::assertArrayHasKey($slice, $report['slices']);
        }
        self::assertFalse($report['extraction']['pdfOcrQualified']);
        self::assertNull($report['extraction']['pdfOcrCoverage']);
        self::assertGreaterThan(0, $report['latencyMs']['p95']);
        self::assertSame(0, $report['stageCost']['providerCalls']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach ($corpus->documents as $doc) {
            self::assertStringNotContainsString(json_encode($doc['text'], JSON_THROW_ON_ERROR), $json);
        }
    }

    public function testCliRejectsUnknownProfileMissingCorpusAndBadOptions(): void
    {
        $qa = new Qa(dirname(__DIR__, 2));
        foreach ([[], ['--corpus', 'missing.json', '--profile', 'postgres-fts-simple-v1', '--output', '/dev/null'],
            ['--corpus', 'tests/fixtures/evaluation/manifest.v1.json', '--profile', 'semantic', '--output', '/dev/null']] as $args) {
            $result = $qa->process([PHP_BINARY, 'tools/evaluate.php', ...$args]);
            self::assertNotSame(0, $result['exit']);
            self::assertStringNotContainsString('password', $result['stderr']);
        }
    }
}
