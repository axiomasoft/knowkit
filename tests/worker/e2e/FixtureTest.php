<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Tests\Worker\Protocol\Fixture as Protocol;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:e2e')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndAllRequiredCasesAreBound(): void
    {
        $qa = new Qa(Protocol::root());
        $manifest = $qa->json('tests/worker/e2e/fixtures/v1/manifest.json');
        self::assertSame('1.1.0', $manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['fixtures'] as $entry) {
            self::assertSame($entry['sha256'], hash_file('sha256', $qa->file($entry['path'])));
            self::assertNotEmpty($entry['expected']['outcome']);
        }
        $group = $qa->json('tests/fixtures/check-groups/worker-e2e.json');
        $qa->fixtures($group);
        foreach (['KILL-BEFORE-UPLOAD', 'KILL-AFTER-UPLOAD', 'KILL-BEFORE-CHECKPOINT', 'KILL-AFTER-CHECKPOINT',
            'STALE-LEASE', 'REVOKE-DURING-OCR', 'CORRUPT-MANIFEST', 'NEW-PARSER', 'OLD-CITATION',
            'STRICT-PARTIAL', 'NETWORK-DENIED', 'R16-A01', 'R16-A02', 'R16-A03'] as $id) {
            self::assertContains($id, $group['requiredCaseIds']);
            self::assertNotEmpty($group['testCaseMap'][$id]);
        }
    }

    public function testRequiredCaseAccountingRejectsMissingSkippedFailedEmptyAndDuplicateExecutions(): void
    {
        $group = (new Qa(Protocol::root()))->json('tests/fixtures/check-groups/worker-e2e.json');
        $cases = [];
        foreach ($group['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        $cases = array_values($cases);
        self::assertCount(count($group['requiredCaseIds']), Qa::validateCases($cases, $group));
        $skipped = $failed = $cases;
        $skipped[0]['status'] = 'skipped';
        $failed[0]['status'] = 'failed';
        foreach ([[], array_slice($cases, 1), $skipped, $failed, [...$cases, $cases[0]]] as $invalid) {
            try {
                Qa::validateCases($invalid, $group);
                self::fail('Incomplete executed-case accounting accepted');
            } catch (\RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
}
