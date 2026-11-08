<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Domain as D;
use KnowKit\Tests\Retrieval\Selection\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('quality:retrieval')]
final class FixtureTest extends TestCase
{
    public function testVersionRightsDigestsAnnotationsAndDonorReceipt(): void
    {
        $root = dirname(__DIR__, 2);
        $qa = new Qa($root);
        $manifest = $qa->json('tests/fixtures/check-groups/quality-retrieval.json');
        $qa->fixtures($manifest);
        $corpus = new Corpus($root . '/tests/fixtures/evaluation/manifest.v1.json');
        self::assertSame(Evaluator::THRESHOLDS, $corpus->manifest['thresholds']);
        self::assertCount(18, $corpus->questions);
        foreach ($corpus->questions as $case) {
            self::assertArrayHasKey($case['id'], $manifest['testCaseMap']);
            foreach ($case['relevant'] as $gold) {
                self::assertSame($gold['sourceVersionId'], $gold['evidence']['sourceVersionId']);
                self::assertSame($gold['documentRevisionId'], $gold['evidence']['documentRevisionId']);
                self::assertSame($gold['spans'], $gold['evidence']['spans']);
                self::assertGreaterThan(0, $gold['grade']);
            }
        }
        $receipt = $qa->json('tests/evaluation/evidence/P5.4-reuse.json');
        self::assertCount(5, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['verified']);
            self::assertTrue($source['read']);
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject', 'reuse']);
        }
        $extraction = Corpus::json($root . '/tests/fixtures/evaluation/extraction.v1.json');
        self::assertFalse($extraction['qualified']);
        self::assertSame(['text', 'scan', 'mixed', 'columns', 'rotation', 'blank', 'multipage-table', 'multipage-table'], array_column($extraction['pages'], 'type'));
    }

    public function testChangedSourceDigestOrRightsCannotLoad(): void
    {
        $root = dirname(__DIR__, 2);
        $source = Corpus::json($root . '/tests/fixtures/evaluation/manifest.v1.json');
        $directory = Qa::temporary();
        try {
            foreach (['digest', 'rights', 'version'] as $mutation) {
                $manifest = $source;
                if ($mutation === 'digest') {
                    $manifest['files'] = [['path' => 'bad.txt', 'sha256' => str_repeat('0', 64), 'license' => 'local', 'origin' => 'negative fixture']];
                    file_put_contents($directory . '/bad.txt', 'changed bytes');
                }
                if ($mutation === 'rights') {
                    $manifest['rights']['approved'] = false;
                }
                if ($mutation === 'version') {
                    $manifest['versionRationale'] = '';
                }
                file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                try {
                    new Corpus($directory . '/manifest.json');
                    self::fail('Invalid corpus accepted');
                } catch (RuntimeException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            }
        } finally {
            Qa::remove($directory);
        }
    }

    public function testMultilingualRewriteRetainsOriginalRevisionAndExactSecurityFilters(): void
    {
        self::assertSame(3, RewriteProbe::run()['unsafeRewritesRejected']);
        $query = new D\Query('Инструкция foo_bar.v2 ревизия 7 固定', [new D\SourceVersionId(Fixture::id(3))], [new D\CollectionId(Fixture::id(9))]);
        $normalizer = new Normalizer();
        $binding = $normalizer->bind($query, Fixture::profile(), D\SelectionLimits::profile('compact'), rewrite: 'Manual foo_bar.v2 revision 7 固定');
        self::assertSame($query, $binding->original);
        self::assertSame($query->sourceVersionIds, $binding->normalized->sourceVersionIds);
        self::assertSame($query->collectionIds, $binding->normalized->collectionIds);
        foreach (['Manual foo_bar.v20 revision 7 固定', 'Manual foo_bar.v2 revision 8 固定'] as $rewrite) {
            try {
                $normalizer->bind($query, Fixture::profile(), D\SelectionLimits::profile('compact'), rewrite: $rewrite);
                self::fail('Identifier broadening accepted');
            } catch (D\DomainFailure $error) {
                self::assertSame('rewrite_identifier_mismatch', $error->errorCode);
            }
        }
        $this->expectException(D\DomainFailure::class);
        new D\QueryBinding($query, new D\Query($query->text(), [new D\SourceVersionId(Fixture::id(4))]),
            'unsafe-rewrite', Fixture::profile(), D\SelectionLimits::profile('compact'));
    }

    public function testRequiredCasesRejectMissingSkippedDuplicateFailedAndEmptyExecution(): void
    {
        $qa = new Qa(dirname(__DIR__, 2));
        $manifest = $qa->json('tests/fixtures/check-groups/quality-retrieval.json');
        $ids = [];
        foreach ($manifest['testCaseMap'] as $mapped) {
            $ids = array_merge($ids, $mapped);
        }
        $passed = array_map(static fn (string $id): array => ['id' => $id, 'status' => 'passed'], array_values(array_unique($ids)));
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases($passed, $manifest));
        $skipped = $failed = $passed;
        $skipped[0]['status'] = 'skipped';
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($passed, 1), [...$passed, $passed[0]], $skipped, $failed] as $cases) {
            try {
                Qa::validateCases($cases, $manifest);
                self::fail('Missing/skipped/failed required tests accepted');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testRunnerRejectsUnknownGroupMissingTestsAndNestedFailure(): void
    {
        $qa = new Qa(dirname(__DIR__, 2));
        self::assertNotSame(0, $qa->process([PHP_BINARY, 'tools/qa.php', 'quality:nonexistent'])['exit']);
        $group = ['id' => 'quality:retrieval', 'composerProject' => '.', 'minPhp' => '8.3',
            'requiredCaseManifest' => 'tests/fixtures/check-groups/quality-retrieval.json'];
        foreach ([[PHP_BINARY, '-r', 'exit(7);'], [PHP_BINARY, 'tests/evaluation/missing-test.php'],
            [PHP_BINARY, '-r', 'echo json_encode(["count"=>0,"cases"=>[],"failures"=>0,"skips"=>0]);']] as $command) {
            try {
                $qa->group($group + ['commands' => [$command]]);
                self::fail('Broken nested command accepted');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
