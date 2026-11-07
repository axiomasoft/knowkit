<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Cache;

use KnowKit\Domain as D;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('retrieval:cache')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesDigestsAndPinnedDonorDecisions(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/cache/v1/manifest.json');
        self::assertSame('reference-cache-fixtures:1', $manifest['schemaVersion']);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $qa->file($file['path'])));
            self::assertNotEmpty($file['expected']);
        }
        $corpus = $qa->json('tests/fixtures/cache/v1/corpus.json');
        self::assertSame($corpus['query']['sha256'], hash('sha256', $corpus['query']['text']));
        $f = new Fixture();
        $scope = $f->host->identity->scope;
        $snapshot = new D\SnapshotRef($scope->tenantId, $scope->kbId, $f->host->inventory->snapshot->snapshotId,
            new D\Fingerprint(str_repeat('b', 64)));
        self::assertSame($corpus['expectedDigests']['cacheKey'], $f->key(snapshot: $snapshot)->digest->value);
    }

    public function testPinnedDonorDecisionsBindExactCandidate(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $receipt = $qa->json('tests/retrieval/cache/evidence/P4.4-reuse.json');
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject', 'reuse-as-is', 'dependency']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['symbols']);
            self::assertNotEmpty($source['targetPaths']);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
        self::assertSame([], $receipt['unresolved']);
        $candidate = '';
        foreach ($receipt['candidateDigest']['paths'] as $path) {
            $candidate .= $path . ':' . hash_file('sha256', $qa->file($path)) . "\n";
        }
        self::assertSame($receipt['candidateDigest']['sha256'], hash('sha256', $candidate));
        $group = $qa->json('tests/fixtures/check-groups/retrieval-cache.json');
        $qa->fixtures($group);
        $group['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(static fn () => $qa->fixtures($group));
    }

    public function testRequiredCasesRejectMissingSkippedFailedEmptyAndUnknownGroup(): void
    {
        require_once __DIR__ . '/CacheTest.php';
        require_once __DIR__ . '/PostgresTest.php';
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/retrieval-cache.json');
        $corpus = $qa->json('tests/fixtures/cache/v1/corpus.json');
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        foreach ($corpus['cases'] as $id => $expectation) {
            self::assertContains($id, $manifest['requiredCaseIds']);
            self::assertNotEmpty($expectation);
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['case'], 'testCaseMap' => ['case' => ['Test::case']]];
        foreach ([[], [['id' => 'Test::other', 'status' => 'passed']],
            [['id' => 'Test::case', 'status' => 'skipped']], [['id' => 'Test::case', 'status' => 'failed']]] as $executed) {
            $this->rejects(static fn () => Qa::validateCases($executed, $required));
        }
        $this->rejects(static fn () => $qa->select(['unknown:reference-cache'], $qa->registry()));
        $this->rejects(static fn () => $qa->file('tests/retrieval/cache/MissingTest.php'));
        self::assertSame(9, $qa->process([PHP_BINARY, '-r', 'exit(9);'])['exit']);
    }

    private function rejects(callable $call): void
    {
        try {
            $call();
            self::fail('Invalid required fixture/check accepted');
        } catch (\RuntimeException $failure) {
            self::assertNotEmpty($failure->getMessage());
        }
    }
}
