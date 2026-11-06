<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

#[Group('tooling:baseline')]
final class RunnerTest extends Support
{
    public function testUnknownGroupIsNonzero(): void
    {
        $child = $this->qa->process([PHP_BINARY, 'tools/qa.php', 'does:not:exist']);
        self::assertNotSame(0, $child['exit']);
        self::assertStringContainsString('unknown QA group', $child['stdout']);
    }

    public function testZeroSelectedTestsIsNonzero(): void
    {
        $child = $this->phpunit(['--testsuite', 'Core', '--group', 'nonexistent:selection']);
        self::assertNotSame(0, $child['exit'], $child['stdout'] . $child['stderr']);
        $this->rejects(static fn() => Qa::validateCases([], ['requiredCaseIds' => ['case']]), 'zero executed');
    }

    public function testFailedSubprocessIsNonzeroEvenWithPassingReport(): void
    {
        $qa = $this->isolatedRunner();
        $group = $this->fakeGroup([PHP_BINARY, 'tests/tooling/baseline/fixtures/v1/child.php', 'child-failure', '<runner-private-junit.xml>']);
        $this->rejects(static fn() => $qa->group($group), 'child failed: fixture exit 17');
    }

    public function testRequiredCasesRejectSkippedMissingDuplicateAndEmptyReports(): void
    {
        $fixture = $this->qa->json('tests/tooling/baseline/fixtures/v1/outcomes.json');
        $manifest = ['requiredCaseIds' => ['case'], 'testCaseMap' => ['case' => ['Fixture::testCase']]];
        foreach ($fixture['junit'] as $scenario => $expected) {
            $path = $this->temporary . '/report.xml';
            $child = $this->qa->process([PHP_BINARY, 'tests/tooling/baseline/fixtures/v1/child.php', $scenario, $path]);
            self::assertSame($expected['exit'], $child['exit']);
            $operation = static fn() => Qa::validateCases(Qa::junit($path), $manifest);
            if ($expected['outcome'] === 'passed') {
                self::assertSame([['id' => 'case', 'status' => 'passed']], $operation());
            } else {
                $this->rejects($operation, $expected['error']);
            }
        }
    }

    public function testUnknownAndAbsentSuitesAreFailures(): void
    {
        $this->copy('phpunit.xml');
        $this->put('tests/tooling/baseline/bootstrap.php', '<?php require ' . var_export($this->qa->root . '/vendor/autoload.php', true) . ';');
        $qa = new Qa($this->temporary);
        $this->rejects(static fn() => $qa->suite('NotASuite'), 'unknown suite');
        $this->rejects(static fn() => $qa->suite('Chatom'), 'missing suite directory');
        $child = $this->phpunit(['--testsuite', 'Chatom'], $qa);
        self::assertNotSame(0, $child['exit']);
        $child = $this->phpunit(['--testsuite', 'NotASuite'], $qa);
        self::assertNotSame(0, $child['exit']);
    }

    public function testProfilesExplicitlyPartitionRegistryWithoutSkippingSelectedGroups(): void
    {
        $registry = $this->qa->registry();
        $ids = array_column($registry['groups'], 'id');
        self::assertSame($ids, $this->qa->select(['all'], $registry));
        $core = $this->qa->select(['--profile', 'core-php83'], $registry);
        self::assertContains('tooling:baseline', $core);
        self::assertNotContains('chatom:contracts', $core);
        self::assertNotContains('bridge-installed', $core);
        $this->copy('tools/qa-groups.json');
        $selected = ['tooling:baseline'];
        $this->put('profile.json', json_encode(['schemaVersion' => 'qa-profile:1', 'selected' => $selected,
            'notSelected' => array_values(array_diff($ids, $selected))], JSON_THROW_ON_ERROR));
        $qa = new Qa($this->temporary);
        self::assertSame($selected, $qa->select(['--profile', 'local-developed', '--manifest', 'profile.json'], $registry));
        $this->put('profile.json', '{"schemaVersion":"qa-profile:1","selected":["tooling:baseline"]}');
        $this->rejects(fn() => $qa->select(['--profile', 'local-developed', '--manifest', 'profile.json'], $registry), 'explicitly partition');
        $registry['profiles']['core-php83']['notSelected'] = [];
        $this->rejects(fn() => $qa->select(['--profile', 'core-php83'], $registry), 'explicitly partition');
    }

    public function testUnavailableLaneAndUninstalledWorkerFailClosed(): void
    {
        $qa = $this->isolatedRunner();
        $group = $this->fakeGroup([PHP_BINARY, '-r', 'exit(0);']);
        $group['minPhp'] = '99.0';
        $this->rejects(static fn() => $qa->group($group), 'lane_unavailable');
        $group['minPhp'] = '8.3';
        $group['id'] = 'worker:protocol';
        $this->rejects(static fn() => $qa->group($group), 'python/knowkit-worker/pyproject.toml');
    }

    public function testRunnerReplacesPlaceholdersAsArgvAndCleansPrivateReports(): void
    {
        $qa = $this->isolatedRunner();
        $group = $this->fakeGroup([PHP_BINARY, 'tests/tooling/baseline/fixtures/v1/child.php', 'valid', '<runner-private-junit.xml>']);
        $before = glob(sys_get_temp_dir() . '/knowkit-qa-*');
        $result = $qa->group($group);
        self::assertSame('PASS', $result['status']);
        self::assertSame([['id' => 'case', 'status' => 'passed']], $result['cases']);
        self::assertSame($before, glob(sys_get_temp_dir() . '/knowkit-qa-*'));
        $this->put('literal $(touch INJECTED).php', '<?php echo "literal";');
        $result = $qa->process([PHP_BINARY, 'literal $(touch INJECTED).php']);
        self::assertSame(0, $result['exit']);
        self::assertSame('literal', $result['stdout']);
        self::assertFileDoesNotExist($this->temporary . '/INJECTED');
    }

    public function testFixtureManifestDigestsAndVersionRationaleAreEnforced(): void
    {
        $manifest = $this->qa->json('tests/fixtures/check-groups/tooling-baseline.json');
        self::assertSame('check-group:1', $manifest['schemaVersion']);
        self::assertNotEmpty($manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']);
        self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['fixtures'] as $entry) {
            self::assertSame($entry['sha256'], hash_file('sha256', $this->qa->file($entry['path'])));
            self::assertNotEmpty($entry['expected']);
        }
        self::assertContains('DT-R01', $manifest['requiredCaseIds']);
        self::assertContains('BOOTSTRAP-R42-A01', $manifest['requiredCaseIds']);
        self::assertContains('BOOTSTRAP-R42-A02', $manifest['requiredCaseIds']);
        $this->qa->fixtures($manifest);
        $changed = $manifest;
        $changed['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->rejects(fn() => $this->qa->fixtures($changed), 'digest mismatch');
        $changed = $manifest;
        $changed['versionRationale'] = '';
        $this->rejects(fn() => $this->qa->fixtures($changed), 'version/rationale');
        $changed = $manifest;
        $changed['schemaVersion'] = 'check-group:99';
        $this->rejects(fn() => $this->qa->fixtures($changed), 'unknown fixture manifest');
        $changed = $manifest;
        $changed['components']['composerLock']['sha256'] = str_repeat('0', 64);
        $this->rejects(fn() => $this->qa->fixtures($changed), 'component lock digest mismatch');
    }

    private function isolatedRunner(): Qa
    {
        $this->put('composer.json', '{}');
        $this->put('vendor/autoload.php', '<?php');
        $this->copy('tests/tooling/baseline/fixtures/v1/child.php');
        $this->put('cases.json', json_encode(['schemaVersion' => 'check-group:1', 'group' => 'fixture',
            'requiredCaseIds' => ['case'], 'testCaseMap' => ['case' => ['Fixture::testCase']]], JSON_THROW_ON_ERROR));
        return new Qa($this->temporary);
    }

    private function fakeGroup(array $command): array
    {
        return ['id' => 'fixture', 'composerProject' => '.', 'minPhp' => '8.3',
            'requiredCaseManifest' => 'cases.json', 'commands' => [$command]];
    }
}
