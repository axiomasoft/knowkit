<?php
declare(strict_types=1);

namespace KnowKit\Tests\Licensing;

use KnowKit\Tests\Tooling\Support;
use KnowKit\Tooling\LicenseAudit;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/tools/license-audit.php';

/** Adapted mandatory-inventory/fresh-process patterns; see licenses/P1.3-reuse.json. */
#[Group('contract:licensing')]
final class LicenseAuditTest extends Support
{
    private array $corpus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->corpus = $this->qa->json('tests/contract/licensing/fixtures/v1/cases.json');
        $this->copy('tools/license-audit.php');
        foreach ($this->corpus['evidencePaths'] as $path) {
            $this->copy($path);
        }
    }

    private function scenario(string $id): array
    {
        $scenario = $this->corpus['cases'][$id];
        $record = array_replace($this->corpus['record'], $scenario['recordOverrides'] ?? []);
        $catalog = $this->corpus['catalog'];
        if (isset($scenario['catalogOverrides'])) {
            $catalog = array_replace($catalog, $scenario['catalogOverrides']);
        }
        $this->put('licenses/qualifications/parser.json', json_encode($record, JSON_THROW_ON_ERROR));
        $this->put('licenses/catalog.json', json_encode($catalog, JSON_THROW_ON_ERROR));
        $result = $this->qa->process([PHP_BINARY, $this->temporary . '/tools/license-audit.php']);
        self::assertSame($scenario['expected']['exit'], $result['exit'], $id . ': ' . $result['stderr']);
        if ($scenario['expected']['exit'] === 0) {
            $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('passed', $report['status']);
            self::assertFalse($report['publicationAllowed']);
        } else {
            self::assertSame('', $result['stdout'], 'Failed qualification must not claim success');
            self::assertStringContainsString($scenario['expected']['error'], $result['stderr'], $id);
        }
        return [$catalog, $record, $result];
    }

    public function testApacheRootDoesNotQualifySeparateWeightsOrDatasets(): void
    {
        foreach (['apache-known-separate-weights', 'apache-unknown-weights', 'apache-denied-weights',
            'apache-unknown-dataset', 'apache-unpinned-weights'] as $id) {
            [, $record] = $this->scenario($id);
            self::assertSame('Apache-2.0', $record['codeLicense']);
        }
        [, , $result] = $this->scenario('apache-known-separate-weights');
        $asset = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['components'][0]['modelAssets'][0];
        self::assertSame('LicenseRef-Synthetic-Weights-Local-Only', $asset['license']);
        self::assertFalse($asset['redistributionAllowed']);
    }

    public function testProprietaryAgentoolsRequiresLocalPermissionAndNoRedistribution(): void
    {
        foreach (['proprietary-local-adaptation', 'proprietary-no-permission', 'proprietary-redistribution'] as $id) {
            $this->scenario($id);
        }
        $record = $this->qa->json('licenses/qualifications/agentools-source-patterns.json');
        self::assertSame('proprietary', $record['codeLicense']);
        self::assertSame('restricted', $record['verdict']);
        self::assertFalse($record['distributionConstraints']['redistributionAllowed']);
        self::assertSame('owner-local-adaptation', $record['distributionConstraints']['requirements'][0]['id']);
    }

    public function testMissingOrCorruptedNoticeRejectsInclusion(): void
    {
        foreach (['missing-notice', 'missing-license-notice', 'missing-notice-file', 'corrupt-notice-digest'] as $id) {
            $this->scenario($id);
        }
    }

    public function testEnabledParserCannotHaveIncompleteOrUnknownQualification(): void
    {
        foreach (['missing-parser-record', 'incomplete-parser-record', 'unknown-parser-license',
            'unpinned-parser-source', 'blocked-parser', 'unfulfilled-requirement',
            'unknown-network', 'profile-not-qualified', 'empty-enabled-parser', 'unknown-catalog-schema'] as $id) {
            $this->scenario($id);
        }
        foreach (['docling', 'mineru', 'pymupdf'] as $id) {
            $record = $this->qa->json('licenses/qualifications/' . $id . '.json');
            self::assertSame('blocked', $record['verdict']);
            self::assertSame([], $record['enabledProfiles']);
        }
    }

    public function testOriginalLocalTxtAndMarkdownDoNotRequireExternalQualification(): void
    {
        [$catalog, , $result] = $this->scenario('local-text-markdown');
        $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $report['components']);
        self::assertSame(['local-text', 'local-markdown'], array_column($report['profiles'], 'id'));
        self::assertSame(['allowed', 'allowed'], array_column($report['profiles'], 'status'));
        $this->scenario('local-external-dependency');
        // Failing optional qualification cannot add a dependency to the local bundle.
        self::assertSame($report, (new LicenseAudit($this->temporary))->audit($catalog));
        $core = $this->qa->json('packages/core/composer.json');
        self::assertSame(['php', 'ext-pdo', 'ext-json', 'ext-mbstring'], array_keys($core['require']));
        self::assertSame('>=8.3', $core['require']['php']);
    }

    public function testTransitiveMissingAndCyclicComponentsAreRejected(): void
    {
        foreach (['missing-transitive-component', 'cyclic-transitive-component'] as $id) {
            $this->scenario($id);
        }
    }

    public function testRepositoryInventoryAndGeneratedSbomAreExactAndOffline(): void
    {
        $audit = new LicenseAudit($this->qa->root);
        $report = $audit->audit($audit->json('licenses/catalog.json'));
        self::assertSame($report, $this->qa->json('licenses/generated/sbom.json'));
        self::assertCount(33, $report['components']);
        self::assertSame(['allowed', 'allowed', 'restricted', 'restricted', 'disabled', 'disabled', 'disabled', 'disabled', 'disabled', 'disabled'],
            array_column($report['profiles'], 'status'));
        foreach ($report['components'] as $record) {
            foreach ($record['notices'] as $notice) {
                $this->copy($notice['path']);
            }
        }
        $this->put('generated/.keep', '');
        (new LicenseAudit($this->temporary))->generate($report, 'generated');
        foreach (['sbom.json', 'THIRD_PARTY_NOTICES.txt'] as $name) {
            self::assertSame(file_get_contents($this->qa->file('licenses/generated/' . $name)),
                file_get_contents($this->temporary . '/generated/' . $name));
        }
    }

    public function testLockDriftAndDependencyGraphMismatchFailClosed(): void
    {
        [$catalog, $record] = $this->scenario('apache-known-separate-weights');
        $lock = ['packages' => [], 'packages-dev' => [[
            'name' => $record['id'], 'version' => $record['version'], 'license' => [$record['codeLicense']],
            'source' => ['reference' => $record['sourceRevision']], 'require' => []]]];
        $this->put('composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $catalog['composerLock'] = ['path' => 'composer.lock', 'sha256' => hash_file('sha256', $this->temporary . '/composer.lock')];
        $audit = new LicenseAudit($this->temporary);
        self::assertSame('passed', $audit->audit($catalog)['status']);
        $lock['packages-dev'][0]['source']['reference'] = str_repeat('b', 40);
        $this->put('composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $this->rejects(fn() => $audit->audit($catalog), 'evidence digest mismatch');
        $catalog['composerLock']['sha256'] = hash_file('sha256', $this->temporary . '/composer.lock');
        $this->rejects(fn() => $audit->audit($catalog), 'locked component not qualified');
        $lock['packages-dev'][0]['source']['reference'] = $record['sourceRevision'];
        $lock['packages-dev'][0]['require'] = ['missing/transitive' => '^1'];
        $this->put('composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $catalog['composerLock']['sha256'] = hash_file('sha256', $this->temporary . '/composer.lock');
        $this->rejects(fn() => $audit->audit($catalog), 'locked dependency graph mismatch');
    }

    public function testVersionedFixtureManifestAndRequiredCasesCannotBeSkipped(): void
    {
        $manifest = $this->qa->json('tests/fixtures/check-groups/contract-licensing.json');
        $this->qa->fixtures($manifest);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $tests) {
            foreach ($tests as $test) {
                $cases[$test] = ['id' => $test, 'status' => 'passed'];
            }
        }
        self::assertCount(count($manifest['requiredCaseIds']), Qa::validateCases(array_values($cases), $manifest));
        $missing = $cases;
        unset($missing[array_key_first($missing)]);
        $this->rejects(fn() => Qa::validateCases(array_values($missing), $manifest), 'missing required case');
        $skipped = array_values($cases);
        $skipped[0]['status'] = 'skipped';
        $this->rejects(fn() => Qa::validateCases($skipped, $manifest), 'failed/skipped');
        $changed = $manifest;
        $changed['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(fn() => $this->qa->fixtures($changed), 'fixture digest mismatch');
        self::assertSame('licensing-fixtures:1', $this->corpus['schemaVersion']);
        self::assertCount(26, $this->corpus['cases']);
    }

    public function testRequiredFieldsAndEvidenceShapesCannotBeBypassed(): void
    {
        [$catalog, $record] = $this->scenario('apache-known-separate-weights');
        $audit = new LicenseAudit($this->temporary);
        foreach (array_keys($record) as $key) {
            $missing = $record;
            unset($missing[$key]);
            $this->put('licenses/qualifications/parser.json', json_encode($missing, JSON_THROW_ON_ERROR));
            $this->rejects(fn() => $audit->audit($catalog), $key === 'id'
                ? 'duplicate/missing component' : 'incomplete qualification: ' . $key);
        }
        foreach ([
            [['sourceUrl' => 'https://user:secret@example.invalid/source'], 'unpinned source'],
            [['sourceUrl' => 'https://example.invalid/source?token=secret'], 'unpinned source'],
            [['sourceUrl' => 'http://example.invalid/source'], 'unpinned source'],
            [['licenseFileDigest' => 'not-a-digest'], 'unknown code license digest'],
            [['modelAssets' => 'unknown'], 'invalid list: modelAssets'],
            [['notices' => ['not-a-notice']], 'invalid notice'],
            [['selectedFiles' => [['path' => 'source.py', 'sha256' => str_repeat('c', 64),
                'sourceUrl' => 'https://example.invalid/latest/source.py', 'span' => 'L1-L2']]], 'unpinned selected source'],
        ] as [$patch, $expected]) {
            $this->put('licenses/qualifications/parser.json', json_encode(array_replace($record, $patch), JSON_THROW_ON_ERROR));
            $this->rejects(fn() => $audit->audit($catalog), $expected);
        }
        $this->rejects(fn() => $audit->file('../outside'), 'invalid local evidence path');
        $this->rejects(fn() => $audit->generate([], '../outside'), 'invalid output directory');
        $this->put('generated/.keep', '');
        symlink($this->temporary . '/generated/.keep', $this->temporary . '/generated/sbom.json');
        $this->rejects(fn() => $audit->generate(['components' => []], 'generated'), 'symlink output rejected');
    }
}
