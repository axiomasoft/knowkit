<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Chunking;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:chunking')]
final class FixtureTest extends TestCase
{
    public function testVersionedOraclesRequiredCasesAndReceiptFailClosed(): void
    {
        $root = ChunkingFixture::root();
        $manifest = json_decode(file_get_contents($root . '/tests/fixtures/chunking/manifest.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $group = json_decode(file_get_contents($root . '/tests/fixtures/check-groups/ingest-chunking.json'), true, 512, JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($group);
        self::assertSame('chunking-fixtures:1', $manifest['schemaVersion']); self::assertSame($group['fixtureVersion'], $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['rights']['license']); self::assertNotEmpty($manifest['rights']['provenance']); self::assertNotEmpty($manifest['versionRationale']);
        foreach ($manifest['cases'] as $case) {
            self::assertSame($case['sha256'], hash_file('sha256', $root . '/' . $case['path']));
            $ir = ChunkingFixture::ir($case['id']); $expected = $case['expected'];
            $options = isset($expected['maxBytes']) ? ['maxBytes' => $expected['maxBytes']] : [];
            $result = ChunkingFixture::chunker($ir)->build($ir, ChunkingFixture::profile($options));
            if (isset($expected['quotes'])) {
                self::assertSame(array_column($expected['quotes'], 'text'), array_map(static fn($c) => $c->text(), $result->chunkSet->chunks));
                self::assertSame(array_column($expected['quotes'], 'sha256'), array_map(static fn($c) => $c->ref->chunkDigest->value, $result->chunkSet->chunks));
            }
            if (isset($expected['quoteDigests'])) { self::assertSame($expected['quoteDigests'], array_map(static fn($c) => $c->ref->chunkDigest->value, $result->chunkSet->chunks)); }
            if (isset($expected['manifestDigest'])) {
                self::assertSame($expected['manifestDigest'], $result->manifestDigest->value);
                self::assertSame($expected['chunkSetId'], $result->chunkSet->chunkSetId->value);
                self::assertSame($expected['chunkId'], $result->chunkSet->chunks[0]->ref->chunkId->value);
            }
        }
        $required = ['CROSS-PAGE', 'OVERSIZED-CODE', 'REPEATED-HEADER', 'MERGED-CELLS', 'UNITS-FOOTNOTES', 'MULTIBYTE-BOUNDARY', 'NEW-CHUNKER',
            'R05-A01', 'R05-A02', 'R10-A01', 'R10-A02', 'R14-A01', 'R14-A02'];
        foreach ($required as $id) { self::assertContains($id, $group['requiredCaseIds']); }
        $executions = [];
        foreach ($group['testCaseMap'] as $methods) {
            foreach ($methods as $name) {
                [$class, $method] = explode('::', $name); $short = substr($class, strrpos($class, '\\') + 1);
                require_once __DIR__ . '/' . $short . '.php'; self::assertTrue(method_exists($class, $method));
                $executions[$name] = ['id' => $name, 'status' => 'passed'];
            }
        }
        // Synthetic execution records test runner rejection only; acceptance uses actual JUnit.
        $cases = array_values($executions); self::assertCount(count($group['requiredCaseIds']), Qa::validateCases($cases, $group));
        $skipped = $cases; $skipped[0]['status'] = 'skipped';
        foreach ([[], array_slice($cases, 1), [...$cases, $cases[0]], $skipped] as $bad) {
            try { Qa::validateCases($bad, $group); self::fail('Empty/missing/duplicate/skipped cases accepted'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        $receipt = json_decode(file_get_contents(__DIR__ . '/evidence/P2.6-reuse.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('P2.6', $receipt['item']); self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['hashVerified']); self::assertTrue($source['sourceRead']);
            self::assertContains($source['decision'], ['reuse-as-is', 'adapt', 'dependency', 'reject']);
            self::assertNotEmpty($source['reason']); self::assertNotEmpty($source['license']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
            foreach ($source['targetPaths'] as $path) { self::assertFileExists($root . '/' . $path); }
        }
    }

    public function testVersionedNegativeProfilesRejectWithoutGuessing(): void
    {
        $negative = StrictJson::decode(file_get_contents(ChunkingFixture::root() . '/tests/fixtures/chunking/negative.v1.json'));
        $ir = ChunkingFixture::ir('cross-page'); self::assertSame('chunking-negatives:1', $negative->get('schemaVersion'));
        foreach ($negative->get('cases') as $case) {
            $profile = new \KnowKit\Domain\ChunkerProfile('negative', 'structural', '1', $case->get('options'));
            ChunkingFixture::failure(fn() => ChunkingFixture::chunker($ir)->build($ir, $profile), $case->get('expected'));
        }
    }
}
