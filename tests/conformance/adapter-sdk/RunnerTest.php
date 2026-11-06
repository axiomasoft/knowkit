<?php
declare(strict_types=1);

namespace KnowKit\Tests\Conformance\AdapterSdk;

use KnowKit\Conformance\{AdapterHarness, SchemaLoader};
use KnowKit\Examples\CustomAdapter\FakeHost;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('extension:conformance-sdk')]
final class RunnerTest extends TestCase
{
    public function testZeroMissingSkippedDuplicateAndUnexpectedExecutedCasesFail(): void
    {
        $pass = ['id'=>'required','status'=>'passed'];
        foreach ([[],[['id'=>'required','status'=>'skipped']],[$pass,$pass],[['id'=>'other','status'=>'passed']],
            [$pass,['id'=>'unexpected','status'=>'passed']],[$pass,['status'=>'passed']]] as $cases) {
            try { AdapterHarness::assertComplete($cases,['required']); self::fail('Invalid report accepted'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        AdapterHarness::assertComplete([$pass],['required']); $this->addToAssertionCount(1);
    }

    public function testCorpusManifestRejectsEmptyMissingDuplicateCorruptAndNoRationale(): void
    {
        $root = Support::root();
        $manifest = json_decode((string) file_get_contents($root . '/packages/core/conformance/manifest.v1.json'),true,512,JSON_THROW_ON_ERROR);
        $directory = sys_get_temp_dir() . '/knowkit-sdk-' . bin2hex(random_bytes(8));
        mkdir($directory,0700); mkdir($directory . '/fixtures',0700);
        copy($root . '/packages/core/conformance/fixtures/corpus.v1.json',$directory . '/fixtures/corpus.v1.json');
        try {
            $invalid = [];
            $value = $manifest; $value['cases'] = []; $invalid[] = $value;
            $value = $manifest; array_pop($value['cases']); $invalid[] = $value;
            $value = $manifest; array_pop($value['cases']); array_pop($value['requiredCaseIds']); $invalid[] = $value;
            $value = $manifest; $value['cases'][] = $value['cases'][0]; $invalid[] = $value;
            $value = $manifest; $value['fixture']['sha256'] = str_repeat('0',64); $invalid[] = $value;
            $value = $manifest; $value['versionRationale'] = ''; $invalid[] = $value;
            $value = $manifest; $value['fixtureVersion'] = ''; $invalid[] = $value;
            $value = $manifest; $value['rights'] = []; $invalid[] = $value;
            $value = $manifest; $value['fixture']['expected']['irDigest'] = str_repeat('0',64); $invalid[] = $value;
            foreach ($invalid as $value) {
                file_put_contents($directory . '/manifest.json',json_encode($value,JSON_THROW_ON_ERROR));
                try { new AdapterHarness(SchemaLoader::load($root . '/schemas'),$directory . '/manifest.json'); self::fail('Broken corpus accepted'); }
                catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
        } finally { unlink($directory . '/manifest.json'); unlink($directory . '/fixtures/corpus.v1.json'); rmdir($directory . '/fixtures'); rmdir($directory); }
    }

    public function testCliRunsCorrectAndRejectsBrokenHostAndUnknownContract(): void
    {
        $base = ['tools/adapter-conformance.php','--profile','examples/custom-adapter/profile.json','--contract','policy'];
        $correct = Support::child($base); self::assertSame(0,$correct['exit'],$correct['stderr']);
        $report = json_decode($correct['stdout'],true,512,JSON_THROW_ON_ERROR);
        self::assertTrue($report['ok']); self::assertGreaterThan(0,$report['executed']);
        self::assertContains('SDK-POLICY',array_column($report['cases'],'id'));
        self::assertContains('unsupported',$report['optionalSuites']);
        $broken = Support::child([...$base,'--factory','examples/custom-adapter/negative-factory.php']);
        self::assertSame(1,$broken['exit']);
        $failed = json_decode($broken['stdout'],true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($failed['ok']); self::assertContains('SDK-POLICY',array_column(array_filter($failed['cases'],static fn(array $c): bool => $c['status'] === 'failed'),'id'));
        $invalid = Support::child(['tools/adapter-conformance.php','--profile','examples/custom-adapter/profile.json','--contract','typo']);
        self::assertSame(1,$invalid['exit']); self::assertStringNotContainsString(Support::root(),$invalid['stdout']);
        $unknown = Support::child(['tools/adapter-conformance.php','--profile','tests/fixtures/host-profiles/unknown-parser.json',
            '--factory','examples/custom-adapter/factory.php','--contract','parser']);
        self::assertSame(1,$unknown['exit']);
        $error = json_decode($unknown['stdout'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame('incompatible',$error['outcome']); self::assertSame('unknown_adapter',$error['code']);
    }

    public function testVersionedGroupManifestMatchesEveryCorpusIdAndFixtureDigest(): void
    {
        $root = Support::root();
        $group = json_decode((string) file_get_contents($root . '/tests/fixtures/check-groups/extension-conformance-sdk.json'),true,512,JSON_THROW_ON_ERROR);
        $corpus = json_decode((string) file_get_contents($root . '/packages/core/conformance/manifest.v1.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame([],array_values(array_diff($corpus['requiredCaseIds'],$group['requiredCaseIds'])));
        self::assertNotEmpty($group['versionRationale']);
        foreach ($group['fixtures'] as $fixture) { self::assertSame($fixture['sha256'],hash_file('sha256',$root . '/' . $fixture['path'])); }
        foreach ($group['requiredCaseIds'] as $id) { self::assertNotEmpty($group['testCaseMap'][$id]); }
    }
}
