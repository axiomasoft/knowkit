<?php

declare(strict_types=1);

namespace KnowKit\Tests\Adoption\Edufy;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EligibilityTest.php';
require_once __DIR__ . '/HostFlowTest.php';

#[Group('adoption:edufy')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndPinnedReuse(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/adoption-edufy.json');
        $qa->fixtures($manifest);
        $qa->suite('Answer');
        $corpus = $qa->json('tests/fixtures/adoption/edufy/v1/cases.json');
        self::assertTrue($corpus['fixture']);
        self::assertSame('HOST QUALIFICATION PENDING', $corpus['qualification']);
        self::assertSame('adoption-edufy:1', $corpus['schemaVersion']);
        self::assertSame($corpus['cases'], $manifest['requiredCaseIds']);
        $receipt = $qa->json('tests/adoption/edufy/evidence/P4.12-reuse.json');
        self::assertSame('P4.12', $receipt['item']);
        self::assertCount(3, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file('.swissknifeman/orch/donor-cache/' . $source['donor'] . '/' . $source['path'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
        self::assertSame(['P4.12-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
    }

    public function testRequiredRunnerRejectsMissingSkippedFailedDuplicateAndZeroCases(): void
    {
        $manifest = (new Qa(dirname(__DIR__, 3)))->json('tests/fixtures/check-groups/adoption-edufy.json');
        self::assertCount(10, $manifest['requiredCaseIds']);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists($class, $name), $method);
                self::assertArrayNotHasKey($method, $cases);
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
                continue;
            }
            self::fail('Runner accepted ' . $fault);
        }
    }
}
