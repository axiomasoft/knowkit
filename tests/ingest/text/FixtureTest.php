<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';

#[Group('ingest:text')]
final class FixtureTest extends TestCase
{
    public function testVersionProvenanceDigestsOutcomesAndRequiredCaseBindings(): void
    {
        $manifest = json_decode(TextFixture::read('tests/fixtures/check-groups/ingest-text.json'), true, 512, JSON_THROW_ON_ERROR);
        (new \KnowKit\Tooling\Qa(TextFixture::root()))->fixtures($manifest);
        $required = [];
        foreach (['text','markdown'] as $format) {
            $f = TextFixture::manifest($format);
            self::assertSame($format . '-fixtures:1', $f['schemaVersion']); self::assertSame($manifest['fixtureVersion'], $f['version']);
            self::assertNotEmpty($f['versionRationale']); self::assertNotEmpty($f['rights']['license']); self::assertNotEmpty($f['rights']['provenance']);
            foreach ($f['cases'] as $case) {
                $required[] = $case['id'];
                self::assertSame($case['sourceDigest'], hash('sha256', TextFixture::read($case['path'])));
                self::assertNotEmpty($case['expected']);
            }
        }
        foreach (array_merge($required, ['R09-A01','R09-A02','R13-A01','R13-A02','R13-A03','DT-I13','DT-I17','TXT-PROFILE','TXT-SIDECAR','TXT-MAP']) as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']); self::assertNotEmpty($manifest['testCaseMap'][$id]);
        }
        self::assertCount(count(array_unique($manifest['requiredCaseIds'])), $manifest['requiredCaseIds']);
        foreach ($manifest['testCaseMap'] as $mapping) {
            foreach ($mapping as $name) {
                [$class,$method] = explode('::', $name); $short = substr($class, strrpos($class, '\\') + 1);
                require_once __DIR__ . '/' . $short . '.php'; self::assertTrue(method_exists($class, $method), $name);
            }
        }
        $receipt = json_decode(file_get_contents(__DIR__ . '/evidence/P2.4-reuse.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('reuse-receipt:1', $receipt['schemaVersion']); self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']);
            self::assertContains($source['decision'], ['adapt','reuse','reject']); self::assertNotEmpty($source['reason']); self::assertNotEmpty($source['license']);
            foreach ($source['targets'] as $path) { self::assertFileExists(TextFixture::root() . '/' . $path); }
        }
    }
}
