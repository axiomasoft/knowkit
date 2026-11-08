<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Contracts;

use Composer\InstalledVersions;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('bridge:contracts')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndInstalledRuntimeMatchPinnedProvenance(): void
    {
        $qa = new Qa(Support::root());
        $manifest = $qa->json('tests/fixtures/check-groups/bridge-contracts.json');
        $qa->fixtures($manifest);
        self::assertSame('1.0.1', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertFalse($manifest['rights']['redistributionAllowed']);
        self::assertSame(Support::PIN, InstalledVersions::getReference('agentools/platform-core'));
        $pin = Support::fixture('source-pin');
        self::assertSame(Support::PIN, $pin['sourceRevision']);
        foreach ($pin['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', Support::donorRoot() . '/' . $file['path']), $file['path']);
            self::assertStringContainsString(Support::PIN, $file['sourceUrl']);
        }
        $receipt = $qa->json('tests/bridge/contracts/evidence/P8.1-reuse.json');
        self::assertTrue($receipt['reviewedBeforeImplementation']);
        self::assertCount(6, $receipt['candidates']);
        foreach ($receipt['candidates'] as $candidate) {
            self::assertContains($candidate['decision'], ['dependency', 'adapt', 'reuse-as-is', 'reject']);
            self::assertNotEmpty($candidate['rationale']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $candidate['sha256']);
        }
        self::assertSame(['P8.1-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
        $qa->suite('Bridge');
    }

    public function testRequiredCaseRunnerRejectsUnknownMissingSkippedZeroAndNestedFailures(): void
    {
        $qa = new Qa(Support::root());
        $registry = $qa->registry();
        $this->rejects(static fn () => $qa->select(args: ['bridge:does-not-exist'], registry: $registry));
        $manifest = ['requiredCaseIds' => ['MODEL-SCOPE-SPOOF'], 'testCaseMap' => ['MODEL-SCOPE-SPOOF' => ['Spoof::testRejects']]];
        self::assertSame([['id' => 'MODEL-SCOPE-SPOOF', 'status' => 'passed']], Qa::validateCases(
            cases: [['id' => 'Spoof::testRejects', 'status' => 'passed']],
            manifest: $manifest,
        ));
        foreach ([[], [['id' => 'Wrong::testMissing', 'status' => 'passed']],
            [['id' => 'Spoof::testRejects', 'status' => 'skipped']],
            [['id' => 'Spoof::testRejects', 'status' => 'failed']]] as $cases) {
            $this->rejects(static fn () => Qa::validateCases(cases: $cases, manifest: $manifest));
        }
        $temporary = Qa::temporary();
        try {
            $this->assertSuiteAndNestedFailures($temporary);
        } finally {
            Qa::remove($temporary);
        }
    }

    private function assertSuiteAndNestedFailures(string $temporary): void
    {
        $qa = new Qa($temporary);
        mkdir($temporary . '/tests');
        file_put_contents($temporary . '/tests/RealTest.php', '<?php');
        file_put_contents($temporary . '/phpunit.xml', '<phpunit><testsuites><testsuite name="Bridge"><directory>tests</directory><directory>missing</directory></testsuite></testsuites></phpunit>');
        $this->rejects(static fn () => $qa->suite('Bridge'));
        mkdir($temporary . '/missing');
        unlink($temporary . '/tests/RealTest.php');
        $this->rejects(static fn () => $qa->suite('Bridge'));
        file_put_contents($temporary . '/junit.xml', '<testsuites><testsuite tests="1" skipped="1"><testcase class="Spoof" name="testRejects"><skipped/></testcase></testsuite></testsuites>');
        $this->rejects(static fn () => Qa::junit($temporary . '/junit.xml'));
        file_put_contents($temporary . '/composer.json', '{}');
        mkdir($temporary . '/vendor');
        file_put_contents($temporary . '/vendor/autoload.php', '<?php');
        file_put_contents($temporary . '/manifest.json', json_encode([
            'schemaVersion' => 'check-group:1',
            'group' => 'bridge:contracts',
            'requiredCaseIds' => ['case'],
        ], JSON_THROW_ON_ERROR));
        $this->rejects(static fn () => $qa->group([
            'id' => 'bridge:contracts',
            'composerProject' => '.',
            'minPhp' => '8.4',
            'requiredCaseManifest' => 'manifest.json',
            'commands' => [[PHP_BINARY, '-r', 'exit(23);']],
        ]));
    }

    private function rejects(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Runner accepted missing/failed proof');
        } catch (\RuntimeException $failure) {
            self::assertNotEmpty($failure->getMessage());
        }
    }
}
