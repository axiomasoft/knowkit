<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:conformance')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixtureAndAllSixPinnedDonorDecisions(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/answer-conformance.json');
        $qa->fixtures($manifest);
        $qa->suite('Answer');
        self::assertSame('answer-replay:1', ReplayFixture::corpus()['schemaVersion']);
        self::assertTrue(ReplayFixture::corpus()['fixture']);
        self::assertSame('HOST QUALIFICATION PENDING', ReplayFixture::corpus()['qualification']);
        self::assertSame(ReplayFixture::corpus()['cases'], $manifest['requiredCaseIds']);
        $receipt = $qa->json('tests/answer/conformance/evidence/P4.11-reuse.json');
        self::assertSame('P4.11', $receipt['item']);
        self::assertCount(6, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file('.swissknifeman/orch/donor-cache/' . $source['donor'] . '/' . $source['path'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
            self::assertFileExists($qa->file($source['qualificationRef']));
        }
        self::assertSame(['P4.11-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedFailedDuplicateAndZeroCases(): void
    {
        foreach (['ReplayTest.php', 'FlowTest.php', 'CallerTest.php', 'MigrationTest.php', 'PostgresFlowTest.php'] as $file) {
            require_once __DIR__ . '/' . $file;
        }
        require_once dirname(__DIR__, 2) . '/concurrency/answer/ConcurrencyTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/answer-conformance.json');
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
            self::fail('Required-case runner accepted ' . $fault);
        }
    }
}
