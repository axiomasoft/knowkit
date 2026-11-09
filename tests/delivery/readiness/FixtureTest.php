<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use KnowKit\Tooling\Qa;
use KnowKit\Tooling\LicenseAudit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__, 3) . '/tools/license-audit.php';

#[Group('delivery:readiness')]
final class FixtureTest extends TestCase
{
    public function testDeliveryLicensingRequalifiesCurrentLockAndRejectsDrift(): void
    {
        $catalog = Fixture::json('deploy/licensing.v1.json');
        $audit = new LicenseAudit(Fixture::root());
        self::assertSame('passed', $audit->audit($catalog)['status']);
        $catalog['composerLock']['sha256'] = str_repeat('0', 64);
        $this->rejects(fn () => $audit->audit($catalog));
    }

    public function testVersionedFixtureDigestsAndSourcePins(): void
    {
        $manifest = Fixture::manifest();
        self::assertSame('check-group:1', $manifest['schemaVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        foreach ($manifest['fixtures'] as $fixture) {
            self::assertSame($fixture['sha256'], hash_file('sha256', Fixture::root() . '/' . $fixture['path']), $fixture['path']);
            self::assertNotEmpty($fixture['expected']);
        }
        $pins = Fixture::json('examples/acceptance/fixtures/v1/deeptutor-source-pins.json');
        self::assertCount(139, $pins['files']);
        $this->validatePins($pins);
        $bad = $pins;
        $bad['files'][0]['sourceUrl'] = 'https://github.com/HKUDS/DeepTutor/blob/main/file.py';
        $this->rejects(fn () => $this->validatePins($bad));
        $bad = $pins;
        unset($bad['files'][0]['sha256']);
        $this->rejects(fn () => $this->validatePins($bad));
        $receipt = Fixture::json('tests/delivery/readiness/evidence/P9.5-reuse.json');
        self::assertCount(5, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame('adapt', $source['decision']);
            self::assertStringContainsString($source['sourceRevision'], $source['sourceUrl']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $source['sha256']);
        }
        foreach (['docs/installation.md', 'docs/operations/runbook.md', 'docs/operations/backup.md',
            'docs/operations/purge.md', 'examples/acceptance/README.md'] as $path) {
            self::assertDoesNotMatchRegularExpression('~/(?:home|Users)/|[A-Z]:\\\\Users\\\\~', file_get_contents(Fixture::root() . '/' . $path));
        }
    }

    public function testRequiredCasesRejectMissingSkippedFailedAndEmptyReports(): void
    {
        $manifest = Fixture::manifest();
        $cases = [];
        foreach ($manifest['testCaseMap'] as $tests) {
            foreach ($tests as $id) {
                $cases[$id] = ['id' => $id, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(cases: array_values($cases), manifest: $manifest));
        $missing = $cases;
        array_pop($missing);
        $this->rejects(fn () => Qa::validateCases(cases: array_values($missing), manifest: $manifest));
        $this->rejects(fn () => Qa::validateCases(cases: [], manifest: $manifest));
        foreach (['skipped', 'failed'] as $status) {
            $bad = array_values($cases);
            $bad[0]['status'] = $status;
            $this->rejects(fn () => Qa::validateCases(cases: $bad, manifest: $manifest));
        }
        $qa = new Qa(Fixture::root());
        $this->rejects(fn () => $qa->select(args: ['unknown-delivery-group'], registry: $qa->registry()));
    }

    private function validatePins(array $manifest): void
    {
        foreach ($manifest['files'] as $file) {
            $revision = $file['sourceRevision'] ?? '';
            if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '') !== 1
                || ($file['sourceUrl'] ?? '') !== 'https://github.com/HKUDS/DeepTutor/blob/' . $revision . '/' . $file['path']) {
                throw new RuntimeException('Unpinned source metadata');
            }
        }
    }

    private function rejects(\Closure $check): void
    {
        try {
            $check();
        } catch (RuntimeException) {
            self::assertTrue(true);

            return;
        }
        self::fail('Invalid acceptance evidence passed');
    }
}
