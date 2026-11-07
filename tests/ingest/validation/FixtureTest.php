<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Validation;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:validation')]
final class FixtureTest extends TestCase
{
    public function testProvenanceVersionDigestsOutcomesAndRequiredExecutedCasesFailClosed(): void
    {
        $group = json_decode(ValidationFixture::read('tests/fixtures/check-groups/ingest-validation.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = ValidationFixture::manifest();
        (new Qa(ValidationFixture::root()))->fixtures($group);
        self::assertSame('ir-validation-fixtures:1', $manifest['schemaVersion']);
        self::assertSame($group['fixtureVersion'], $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['rights']['license']); self::assertNotEmpty($manifest['rights']['provenance']);
        self::assertNotEmpty($manifest['versionRationale']);
        foreach ($manifest['cases'] as $entry) {
            self::assertContains($entry['id'], $group['requiredCaseIds']);
            self::assertSame($entry['sha256'], hash('sha256', ValidationFixture::read($entry['path'])));
            self::assertSame($entry['sourceDigest'], hash('sha256', ValidationFixture::read($entry['sourcePath'])));
            self::assertNotEmpty($entry['expected']);
            foreach ($entry['assets'] ?? [] as $asset) {
                $bytes = ValidationFixture::read($asset['path']);
                self::assertSame($asset['sha256'], hash('sha256', $bytes)); self::assertSame($asset['byteLength'], (string) strlen($bytes));
            }
        }
        $mandatory = ['R04-A01','R04-A02','R07-A01','R07-A02','R08-A01','R08-A02','R09-A01','R09-A02','R16-A01','R16-A02','R16-A03','DT-I01','DT-I02',
            'IR-TAMPER','CHUNK-TAMPER','IR-UTF8-SPLIT','IR-CYCLE','MULTI-SPANS','BLANK-PAGE','MISSING-SCAN','PARTIAL-VS-STRICT'];
        foreach ($mandatory as $id) { self::assertContains($id, $group['requiredCaseIds']); }
        $executed = [];
        foreach ($group['testCaseMap'] as $names) {
            foreach ($names as $name) {
                [$class, $method] = explode('::', $name); $short = substr($class, strrpos($class, '\\') + 1);
                require_once __DIR__ . '/' . $short . '.php'; self::assertTrue(method_exists($class, $method), $name);
                $executed[$name] = ['id' => $name, 'status' => 'passed'];
            }
        }
        // These synthetic reports test the existing runner's fail-closed behavior;
        // the conductor independently maps actual JUnit results after this task.
        $cases = array_values($executed);
        self::assertCount(count($group['requiredCaseIds']), Qa::validateCases($cases, $group));
        $skipped = $cases; $skipped[0]['status'] = 'skipped';
        foreach ([[], array_slice($cases, 1), [...$cases, $cases[0]], $skipped] as $invalid) {
            try { Qa::validateCases($invalid, $group); self::fail('Missing/skipped/empty execution accepted'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        $receipt = json_decode(file_get_contents(__DIR__ . '/evidence/P2.5-reuse.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('P2.5', $receipt['item']); self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']);
            self::assertContains($source['decision'], ['adapt','reuse-as-is','reject','dependency']); self::assertNotEmpty($source['license']);
            self::assertNotEmpty($source['reason']); self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
            foreach ($source['targetPaths'] as $path) { self::assertFileExists(ValidationFixture::root() . '/' . $path); }
        }
    }
}
