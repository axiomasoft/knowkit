<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Load\Report;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class FixtureTest extends TestCase
{
    public function testVersionRightsDigestsCasesAndPinnedReuseAreComplete(): void
    {
        $qa = new Qa(Report::root());
        $manifest = $qa->json('tests/fixtures/check-groups/ops-performance.json');
        $qa->fixtures($manifest);
        $qa->suite('Ops');
        self::assertSame('ops-performance:1', Fixture::cases()['schemaVersion']);
        self::assertSame(Fixture::cases()['fixtureVersion'], $manifest['fixtureVersion']);
        self::assertNotEmpty(Fixture::cases()['versionRationale']);
        foreach (array_keys(Fixture::cases()['outcomes']) as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        $receipt = $qa->json('tests/ops/performance/evidence/P9.4-reuse.json');
        self::assertCount(5, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file($source['cachePath'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['license']);
            self::assertNotEmpty($source['reason']);
        }
        self::assertSame(['P9.4-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
        self::assertSame(4194304, Report::profile()['quotas']['txtDocumentBytes']);
        self::assertSame(10000, Report::profile()['reference']['sources']);
        self::assertSame(100000, Report::profile()['reference']['chunks']);
        self::assertSame(20, Report::profile()['reference']['searchClients']);
    }

    public function testRequiredCaseCheckerRejectsMissingSkippedFailedDuplicateZeroAndUnknown(): void
    {
        $qa = new Qa(Report::root());
        $manifest = $qa->json('tests/fixtures/check-groups/ops-performance.json');
        foreach (glob(__DIR__ . '/*Test.php') as $file) {
            require_once $file;
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
                self::fail('Required-case checker accepted ' . $fault);
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        self::assertNotSame(0, $qa->process([PHP_BINARY, 'tools/qa.php', 'ops:performance-unknown'])['exit']);
        self::assertSame(7, $qa->process([PHP_BINARY, '-r', 'exit(7);'])['exit']);
    }
}
