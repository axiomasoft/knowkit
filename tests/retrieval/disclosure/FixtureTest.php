<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixtureDigestsAndPinnedReuseReceipt(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/evidence/v1/manifest.json');
        self::assertSame('disclosure-fixtures:1', $manifest['schemaVersion']);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        $corpus = $qa->json('tests/fixtures/evidence/v1/corpus.json');
        foreach ($manifest['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $qa->file($file['path'])));
            self::assertNotEmpty($file['expected']);
        }
        self::assertSame($manifest['expectedDigests']['pdfBytes'], hash('sha256', $corpus['page']['rawSource']));
        self::assertSame($manifest['expectedDigests']['canonicalPage'], hash('sha256', $corpus['page']['canonicalText']));
        self::assertSame($manifest['expectedDigests']['quote'], hash('sha256', $corpus['page']['expectedQuote']));
        self::assertSame($manifest['expectedDigests']['injection'], hash('sha256', $corpus['injection']['text']));
        $receipt = $qa->json('tests/retrieval/disclosure/evidence/P4.2-reuse.json');
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            // Donor bytes live in the gitignored local cache and are not part of the public tree.
            self::assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', $source['sha256']));
            self::assertStringContainsString('/' . $source['pin'] . '/' . $source['path'], $source['sourceUrl']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
            self::assertContains($source['decision'], ['adapt', 'reject', 'reuse-as-is', 'dependency']);
            self::assertFileExists($qa->file($source['qualificationRef']));
            foreach ($source['targetPaths'] as $path) {
                self::assertFileExists($qa->root . '/' . $path);
            }
        }
        $group = $qa->json('tests/fixtures/check-groups/retrieval-disclosure.json');
        $qa->fixtures($group);
        $group['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(fn () => $qa->fixtures($group));
    }

    public function testRequiredCaseMapRejectsMissingSkippedEmptyAndFailed(): void
    {
        foreach (['DisclosureTest', 'CanonicalTest', 'CommitTest', 'ConcurrencyTest', 'PublicBoundaryTest'] as $class) {
            require_once __DIR__ . '/' . $class . '.php';
        }
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/retrieval-disclosure.json');
        $corpus = $qa->json('tests/fixtures/evidence/v1/corpus.json');
        foreach (array_keys($corpus['cases']) as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $required = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['Actual::required']]];
        foreach ([[], [['id' => 'Actual::different', 'status' => 'passed']],
            [['id' => 'Actual::required', 'status' => 'skipped']], [['id' => 'Actual::required', 'status' => 'failed']]] as $executed) {
            $this->rejects(static fn () => Qa::validateCases($executed, $required));
        }
    }

    public function testUnknownGroupMissingFileAndNestedFailureCannotPass(): void
    {
        $qa = new Qa(Fixture::root());
        $this->rejects(fn () => $qa->select(['unknown:disclosure-fixture'], $qa->registry()));
        $this->rejects(fn () => $qa->file('tests/retrieval/disclosure/missing-required-test.php'));
        $failed = $qa->process([PHP_BINARY, '-r', 'exit(9);']);
        self::assertSame(9, $failed['exit']);
    }

    private function rejects(callable $call): void
    {
        try {
            $call();
            self::fail('Required runner invariant accepted invalid input');
        } catch (\RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());
        }
    }
}
