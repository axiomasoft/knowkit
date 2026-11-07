<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Commands;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:commands')]
final class FixtureTest extends TestCase
{
    public function testVersionedOutcomesDigestsAndRequiredCaseMappingsFailClosed(): void
    {
        $root = dirname(__DIR__,3);
        $group = json_decode(file_get_contents($root.'/tests/fixtures/check-groups/lifecycle-commands.json'),true,512,JSON_THROW_ON_ERROR);
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'),true,512,JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($group);
        self::assertSame('lifecycle-commands-fixtures:1',$fixture['schemaVersion']);
        self::assertSame($group['fixtureVersion'],$fixture['fixtureVersion']); self::assertNotEmpty($fixture['versionRationale']);
        self::assertSame($group['requiredCaseIds'],array_column($fixture['cases'],'id'));
        foreach ($fixture['cases'] as $case) { self::assertNotEmpty($case['expected']); }
        $d = $fixture['digests'];
        self::assertSame($d['sourceContent'],hash('sha256','version-3'));
        self::assertSame($d['replacementContent'],hash('sha256','version-30'));
        self::assertSame($d['revokePayload'],HashDomain::DomainEventPayload->digest(new JsonObject([
            'sourceId'=>Fixture::id(2),'reason'=>'source_revoked','lifecycleEpoch'=>'1','appliesTo'=>'future_versions'])));
        self::assertSame($d['sourceIdentity'],HashDomain::SourceIdentity->digest(new JsonObject(['tenantId'=>Fixture::id(1),'sourceId'=>Fixture::id(2)])));
        $reports=[];
        foreach ($group['testCaseMap'] as $names) {
            foreach ($names as $name) {
                [$class,$method] = explode('::',$name); $short = substr($class,strrpos($class,'\\')+1);
                require_once __DIR__.'/'.$short.'.php'; self::assertTrue(method_exists($class,$method));
                $reports[$name]=['id'=>$name,'status'=>'passed'];
            }
        }
        // Synthetic reports exercise rejection only; acceptance binds real JUnit executions.
        $reports=array_values($reports); self::assertCount(count($group['requiredCaseIds']),Qa::validateCases($reports,$group));
        $bad=[[],[...$reports,$reports[0]]];
        foreach (array_keys($reports) as $i) {
            $missing=$reports; unset($missing[$i]); $bad[]=array_values($missing);
            foreach (['skipped','failed'] as $status) { $changed=$reports; $changed[$i]['status']=$status; $bad[]=$changed; }
        }
        foreach ($bad as $report) {
            try { Qa::validateCases($report,$group); self::fail('Missing, skipped, failed, empty or duplicate execution accepted'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        $receipt=json_decode(file_get_contents(__DIR__.'/evidence/P3.2-reuse.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(7,$receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']); self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']); self::assertContains($source['decision'],['adapt','reject','reuse-as-is']);
            foreach ($source['targetPaths'] as $path) { self::assertFileExists($root.'/'.$path); }
        }
    }
}
