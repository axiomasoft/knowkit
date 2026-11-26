<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Transport;

use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:transport')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndVerifiedReuseReceipt(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/answer-transport.json');
        $qa->fixtures($manifest);
        $qa->suite('Answer');
        self::assertSame('answer-transport:1', TransportFixture::corpus()['schemaVersion']);
        self::assertSame('1.0.0', TransportFixture::corpus()['fixtureVersion']);
        $receipt = $qa->json('tests/answer/transport/evidence/P4.9-reuse.json');
        self::assertSame('P4.9', $receipt['item']);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            $this->assertPinnedDonor($source);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
    }

    public function testRequiredManifestRejectsMissingSkippedFailedDuplicateAndZeroCases(): void
    {
        require_once __DIR__ . '/TransportTest.php';
        require_once __DIR__ . '/EgressTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/answer-transport.json');
        self::assertCount(9, $manifest['requiredCaseIds']);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(9, Qa::validateCases(array_values($cases), $manifest));
        foreach (['missing', 'skipped', 'failed', 'duplicate', 'zero'] as $fault) {
            $executed = array_values($cases);
            match ($fault) {
                'missing' => array_shift($executed),
                'skipped', 'failed' => $executed[0]['status'] = $fault,
                'duplicate' => $executed[] = $executed[0],
                'zero' => $executed = [],
            };
            try {
                Qa::validateCases($executed, $manifest);
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());

                continue;
            }
            self::fail("Runner accepted {$fault} cases");
        }
    }

    /** @param array<string, mixed> $source */
    private function assertPinnedDonor(array $source): void
    {
        // The private donor checkout is not part of the public package.
        self::assertSame($source['sha256'], $source['observedSha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $source['sha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/', $source['pin']);
        self::assertStringStartsWith('https://', $source['sourceUrl']);
        self::assertStringContainsString('/' . $source['pin'] . '/' . $source['path'], $source['sourceUrl']);
        self::assertContains($source['decision'], ['adapt', 'reject']);
        self::assertNotEmpty($source['license']);
        self::assertNotEmpty($source['reason']);
    }
}
