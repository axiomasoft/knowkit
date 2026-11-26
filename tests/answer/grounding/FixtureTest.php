<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Grounding;

use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:grounding')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndVerifiedDonorDecisions(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/answer-grounding.json');
        $qa->fixtures($manifest);
        $qa->suite('Answer');
        self::assertSame('answer-grounding:1', GroundingFixture::corpus()['schemaVersion']);
        self::assertSame('1.0.0', GroundingFixture::corpus()['fixtureVersion']);
        self::assertSame(GroundingFixture::corpus()['cases'], $manifest['requiredCaseIds']);
        $receipt = $qa->json('tests/answer/grounding/evidence/P4.10-reuse.json');
        self::assertSame('P4.10', $receipt['item']);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            $this->assertPinnedDonor($source);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
    }

    public function testRequiredManifestRejectsMissingSkippedFailedDuplicateAndZeroCases(): void
    {
        require_once __DIR__ . '/GroundingTest.php';
        require_once __DIR__ . '/SafetyTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/answer-grounding.json');
        self::assertCount(10, $manifest['requiredCaseIds']);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(10, Qa::validateCases(array_values($cases), $manifest));
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
