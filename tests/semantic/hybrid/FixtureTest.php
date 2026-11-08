<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Hybrid;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:hybrid')]
final class FixtureTest extends TestCase
{
    private function json(string $path): array
    {
        return json_decode(file_get_contents(Fixture::root() . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testVersionedFixtureDigestsAndVerifiedReuseReceipt(): void
    {
        $group = $this->json('tests/fixtures/check-groups/semantic-hybrid.json');
        $manifest = $this->json('tests/fixtures/retrieval/hybrid/manifest.v1.json');
        $corpus = $this->json('tests/fixtures/retrieval/hybrid/corpus.v1.json');
        self::assertSame('check-group:1', $group['schemaVersion']);
        self::assertSame('hybrid-fixtures:1', $manifest['schemaVersion']);
        self::assertSame('hybrid-corpus:1', $corpus['schemaVersion']);
        foreach ([$group, $manifest, $corpus] as $document) {
            self::assertNotEmpty($document['versionRationale']);
            self::assertNotEmpty($document['rights']['license']);
            self::assertNotEmpty($document['rights']['origin']);
        }
        foreach ([...$group['fixtures'], ...$manifest['entries']] as $entry) {
            self::assertSame($entry['sha256'], hash_file('sha256', Fixture::root() . '/' . $entry['path']));
            self::assertNotEmpty($entry['expected']);
        }
        foreach (['R21-A01', 'R21-A02', 'R23-A01', 'R23-A02', 'DT-Q08', 'no-lexical-match',
            'exact-id-vs-semantic-noise', 'duplicate-tie', 'invented-id-widened-span', 'timeout',
            'allowed-lexical-degradation', 'forbidden-fallback'] as $id) {
            self::assertContains($id, $group['requiredCaseIds']);
            self::assertNotEmpty($group['testCaseMap'][$id]);
        }
        $receipt = $this->json('tests/semantic/hybrid/evidence/P5.3-reuse.json');
        self::assertSame('P5.3', $receipt['item']);
        self::assertCount(8, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['read']);
            self::assertTrue($source['verified']);
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['reuse', 'adapt', 'reject']);
            self::assertNotEmpty($source['rationale']);
            self::assertStringContainsString('/' . $source['revision'] . '/' . $source['path'], $source['url']);
        }
    }

    public function testRequiredCasesRejectMissingSkippedFailedDuplicateAndEmptyExecution(): void
    {
        $manifest = $this->json('tests/fixtures/check-groups/semantic-hybrid.json');
        $names = array_values(array_unique(array_merge(...array_values($manifest['testCaseMap']))));
        $passed = array_map(static fn (string $id): array => ['id' => $id, 'status' => 'passed'], $names);
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases($passed, $manifest));
        $skipped = $passed;
        $skipped[0]['status'] = 'skipped';
        $failed = $passed;
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($passed, 1), $skipped, $failed, [...$passed, $passed[0]]] as $invalid) {
            try {
                Qa::validateCases($invalid, $manifest);
                self::fail('Invalid execution report accepted');
            } catch (\RuntimeException $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }
}
