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
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file('.swissknifeman/orch/donor-cache/' . $source['donor'] . '/' . $source['path'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
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
            } catch (\RuntimeException) {
                self::assertTrue(true);

                continue;
            }
            self::fail("Runner accepted {$fault} cases");
        }
    }
}
