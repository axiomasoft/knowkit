<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Selection;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:selection')]
final class FixtureTest extends TestCase
{
    public function testVersionedCorpusGoldenDigestsAndDonorReceipt(): void
    {
        $root = Fixture::root();
        $manifest = json_decode(file_get_contents($root . '/tests/fixtures/retrieval/selection/manifest.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('selection-fixtures:1', $manifest['schemaVersion']);
        self::assertSame('1.0.0', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $root . '/' . $file['path']));
            self::assertNotEmpty($file['expected']);
        }
        $data = Fixture::corpus();
        foreach ($data['documents'] as $doc) {
            self::assertSame($doc['textDigest'], hash('sha256', $doc['text']));
        }
        $vector = $data['queryVector'];
        $q = (new Fixture())->query(text: $vector['payload']['originalQuery']);
        self::assertEquals(StrictJson::decode(json_encode($vector['payload'], JSON_THROW_ON_ERROR)), $q->toWire());
        self::assertSame($vector['digest'], $q->digest()->value);
        $receipt = json_decode(file_get_contents(__DIR__ . '/evidence/P4.1-reuse.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertFileExists($root . '/' . $source['qualificationRef']);
        }
    }

    public function testRequiredManifestMapsRealCasesAndRejectsMissingSkippedOrEmpty(): void
    {
        $root = Fixture::root();
        $manifest = json_decode(file_get_contents($root . '/tests/fixtures/check-groups/retrieval-selection.json'), true, 512, JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($manifest);
        require_once __DIR__ . '/SelectionTest.php';
        require_once __DIR__ . '/PostgresTest.php';
        foreach (['R06-A01', 'R06-A02', 'R24-A01', 'R24-A02', 'R24-A03', 'R25-A01', 'R25-A02', 'R27-A01', 'R27-A02',
            'EXPIRED-SELECTION', 'AUDIENCE-CHANGE', 'REWRITE-EXACT-ID', 'OVERLAP', 'LARGE-TABLE-READ',
            'OUTCOME-SUCCESS', 'OUTCOME-EMPTY', 'OUTCOME-DENIED', 'OUTCOME-UNKNOWN', 'OUTCOME-UNAVAILABLE', 'OUTCOME-INCOMPATIBLE'] as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
            }
        }
        $required = ['requiredCaseIds' => ['required'], 'testCaseMap' => ['required' => ['Actual::required']]];
        foreach ([[], [['id' => 'Actual::different', 'status' => 'passed']], [['id' => 'Actual::required', 'status' => 'skipped']],
            [['id' => 'Actual::required', 'status' => 'failed']]] as $cases) {
            try {
                Qa::validateCases($cases, $required);
                self::fail('Missing/skipped/failed/empty test accepted');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
}
