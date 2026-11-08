<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Observability;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('ops:observability')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndFivePinnedReuseDecisions(): void
    {
        $qa = new Qa(Fixture::root());
        $manifest = $qa->json('tests/fixtures/check-groups/ops-observability.json');
        $qa->fixtures($manifest);
        $qa->suite('Ops');
        self::assertSame('ops-observability:1', Fixture::cases()['schemaVersion']);
        self::assertSame(Fixture::cases()['fixtureVersion'], $manifest['fixtureVersion']);
        foreach (['PROVIDER-TIMEOUT', 'STALE-LEASE', 'DENIED-DISCLOSURE', 'OPTIONAL-OCR-DOWN',
            'UNKNOWN-PURGE-RECEIPT', 'CANARY-REDACTION', 'TRACE-PROPAGATION', 'DT-Q10'] as $id) {
            self::assertContains($id, $manifest['requiredCaseIds']);
        }
        $receipt = $qa->json('tests/ops/observability/evidence/P9.1-reuse.json');
        self::assertCount(5, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertSame($source['sha256'], hash_file('sha256', $qa->file($source['cachePath'])));
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
        }
        self::assertSame(['P9.1-reuse.json'], array_values(array_diff(scandir(__DIR__ . '/evidence'), ['.', '..'])));
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedFailedDuplicateAndZeroTests(): void
    {
        require_once __DIR__ . '/TelemetryTest.php';
        require_once __DIR__ . '/OperationsTest.php';
        require_once __DIR__ . '/DiagnosticsTest.php';
        $manifest = (new Qa(Fixture::root()))->json('tests/fixtures/check-groups/ops-observability.json');
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
            } catch (\RuntimeException) {
                continue;
            }
            self::fail('Required case runner accepted ' . $fault);
        }
    }
}
