<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Embedding;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:embedding')]
final class FixtureTest extends TestCase
{
    private function json(string $path): array
    {
        return json_decode(file_get_contents(Fixture::root() . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testVersionedFixtureDigestsAndReuseEvidence(): void
    {
        $group = $this->json('tests/fixtures/check-groups/semantic-embedding.json');
        $manifest = $this->json('tests/fixtures/embeddings/manifest.v1.json');
        self::assertSame('check-group:1', $group['schemaVersion']);
        self::assertSame('embedding-fixtures:1', $manifest['schemaVersion']);
        self::assertSame('embedding-corpus:1', Fixture::corpus()['schemaVersion']);
        foreach ([$group, $manifest, Fixture::corpus()] as $document) {
            self::assertNotEmpty($document['rights']['license']);
            self::assertNotEmpty($document['rights']['origin']);
            self::assertNotEmpty($document['versionRationale']);
        }
        foreach ([...$group['fixtures'], ...$manifest['entries']] as $entry) {
            self::assertSame($entry['sha256'], hash_file('sha256', Fixture::root() . '/' . $entry['path']));
            self::assertNotEmpty($entry['expected']);
        }
        foreach (['R12-A01', 'R12-A02', 'R22-A01', 'R22-A02', 'DT-Q02'] as $id) {
            self::assertContains($id, $group['requiredCaseIds']);
            self::assertNotEmpty($group['testCaseMap'][$id]);
        }
        $receipt = $this->json('tests/semantic/embedding/evidence/P5.1-reuse.json');
        self::assertSame('P5.1', $receipt['item']);
        self::assertCount(8, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['read']);
            self::assertTrue($source['verified']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $source['sha256']);
            self::assertStringContainsString('/' . $source['revision'] . '/' . $source['path'], $source['url']);
            self::assertContains($source['decision'], ['adapt', 'defer']);
            self::assertNotEmpty($source['rationale']);
        }
    }

    public function testRequiredCasesRejectMissingSkippedFailedDuplicateAndZeroExecutions(): void
    {
        $manifest = $this->json('tests/fixtures/check-groups/semantic-embedding.json');
        $ids = array_values(array_unique(array_merge(...array_values($manifest['testCaseMap']))));
        $passed = array_map(static fn(string $id): array => ['id' => $id, 'status' => 'passed'], $ids);
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases($passed, $manifest));
        $skipped = $passed;
        $skipped[0]['status'] = 'skipped';
        $failed = $passed;
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($passed, 1), $skipped, $failed, [...$passed, $passed[0]]] as $bad) {
            try {
                Qa::validateCases($bad, $manifest);
                self::fail('Invalid execution inventory accepted');
            } catch (\RuntimeException $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }
}
