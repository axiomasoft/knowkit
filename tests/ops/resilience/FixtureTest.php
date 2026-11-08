<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class FixtureTest extends TestCase
{
    public function testVersionProvenanceDigestsAndMandatoryCasesBindExecutableAssertions(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/ops-resilience.json');
        $qa->fixtures($manifest);
        $qa->suite('Ops');
        self::assertSame('ops-resilience:1', Fixture::cases()['schemaVersion']);
        self::assertSame(Fixture::cases()['fixtureVersion'], $manifest['fixtureVersion']);
        self::assertNotEmpty(Fixture::cases()['versionRationale']);
        foreach (['PUBLISH-REVOKE', 'GRAPH-REGISTER-INVALIDATE', 'UNSEEN-ROOT-TOMBSTONE',
            'LEASE-TAKEOVER-COMPLETE', 'PURGE-LATE-RESOURCE', 'CACHE-HIT-REVOKE', 'RESTORE-READINESS',
            'KILL-BEFORE-COMMIT', 'KILL-AFTER-COMMIT', 'OBJECT-UNCERTAINTY', 'PROVIDER-BEFORE-EFFECT',
            'PROVIDER-AFTER-EFFECT', 'DUPLICATE-OUTBOX', 'TWO-INGEST', 'TWO-PUBLICATIONS',
            'SHARED-WRITER-RESTORE-GUARD', 'RESTORE-FAIL-CLOSED'] as $case) {
            self::assertContains($case, $manifest['requiredCaseIds']);
        }
        $receipt = $qa->json('tests/ops/resilience/evidence/P9.3-reuse.json');
        self::assertCount(7, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file($source['cachePath'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['license']);
            self::assertNotEmpty($source['reason']);
        }
        self::assertSame(['P9.3-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
        foreach ([...glob(__DIR__ . '/*.php'), ...glob(Fixture::root() . '/tools/fault-injection/*.php')] as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(?:u?sleep|time_nanosleep)\s*\(/', file_get_contents($file), $file);
        }
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedFailedDuplicateEmptyAndUnknownChecks(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/ops-resilience.json');
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
        $unknown = $qa->process([PHP_BINARY, 'tools/qa.php', 'ops:resilience-does-not-exist']);
        self::assertNotSame(0, $unknown['exit']);
        $failed = $qa->process([PHP_BINARY, '-r', 'exit(7);']);
        self::assertSame(7, $failed['exit']);
    }
}
