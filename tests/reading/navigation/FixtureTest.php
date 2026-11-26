<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('reading:navigation')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndEveryPinnedDonorDigest(): void
    {
        $qa = new Qa(PortFixture::root());
        $corpus = Fixture::corpus();
        self::assertSame('navigation-corpus:1', $corpus['schemaVersion']);
        self::assertSame('1.0.0', $corpus['fixtureVersion']);
        self::assertNotEmpty($corpus['versionRationale']);
        self::assertNotEmpty($corpus['rights']['license']);
        $receipt = $qa->json('tests/reading/navigation/evidence/P4.6-reuse.json');
        self::assertSame('P4.6', $receipt['item']);
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
        $manifest = $qa->json('tests/fixtures/check-groups/reading-navigation.json');
        $qa->fixtures($manifest);
        $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(fn () => $qa->fixtures($manifest));
    }

    public function testRequiredManifestRejectsMissingSkippedFailedAndZeroTestSuccess(): void
    {
        foreach (['CanonicalTest', 'ServiceTest', 'ProtocolTest', 'ExampleTest'] as $file) {
            require_once __DIR__ . '/' . $file . '.php';
        }
        $qa = new Qa(PortFixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/reading-navigation.json');
        foreach (['R08-A01', 'R08-A02', 'DT-L01', 'DT-L02'] as $id) {
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
        foreach ([[], [['id' => 'Actual::other', 'status' => 'passed']],
            [['id' => 'Actual::required', 'status' => 'skipped']], [['id' => 'Actual::required', 'status' => 'failed']]] as $executed) {
            $this->rejects(static fn () => Qa::validateCases($executed, $required));
        }
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid fixture/check result accepted');
        } catch (\RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());
        }
    }
}
