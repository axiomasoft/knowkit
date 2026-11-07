<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Domain as D;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:dependencies')]
final class FixtureTest extends TestCase
{
    public function testVersionedDigestsProvenanceAndRequiredCasesFailClosed(): void
    {
        $root=dirname(__DIR__,3);
        $group=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/lifecycle-dependencies.json'),true,512,JSON_THROW_ON_ERROR);
        $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'),true,512,JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($group);
        self::assertSame('lifecycle-dependencies-fixtures:1',$fixture['schemaVersion']);
        self::assertSame($group['fixtureVersion'],$fixture['fixtureVersion']); self::assertNotEmpty($fixture['versionRationale']);
        self::assertSame($group['requiredCaseIds'],array_column($fixture['cases'],'id'));
        foreach($fixture['cases'] as $case) { self::assertNotEmpty($case['expected']); }
        Fixture::run(function($f) use($fixture) {
            $d=$fixture['digests']; $f->version(30);
            $f->atomic(function() use($f,$d) {
                $a=$f->register('source_version',3); $b=$f->register('source_version',30); $n=$f->derived(500,[$b->ref,$a->ref]);
                self::assertSame($d['sourceNode'],$a->identityDigest->value); self::assertSame($d['resourceNode'],$n->identityDigest->value);
                self::assertSame($d['emptyParents'],(new D\Parents($f->tenant,[]))->digest()->value);
                self::assertSame($d['oneParent'],(new D\Parents($f->tenant,[$a->ref]))->digest()->value);
                self::assertSame($d['twoParents'],(new D\Parents($f->tenant,[$b->ref,$a->ref]))->digest()->value);
                self::assertSame($d['sourceContent'],$f->catalog->db->row('SELECT source_digest FROM source_versions WHERE id=?',[Fixture::id(3)])['source_digest']);
                self::assertSame($d['resourceContent'],$f->catalog->db->row('SELECT digest FROM resource_ledger WHERE id=?',[Fixture::id(500)])['digest']);
            });
        });
        foreach(['TOMBSTONE-BEFORE-NODE','CHANGED-PARENT-SET','CYCLE','CROSS-TENANT-EDGE','DEEP-INVALID-ANCESTOR','LATE-DERIVATIVE',
            'EXACT-VERSION-CORRECTION','R04-A01','R04-A02','R41-A01','R41-A02'] as $id) { self::assertContains($id,$group['requiredCaseIds']); }
        $reports=[];
        foreach($group['testCaseMap'] as $names) {
            foreach($names as $name) {
                [$class,$method]=explode('::',$name); $short=substr($class,strrpos($class,'\\')+1); require_once __DIR__.'/'.$short.'.php';
                self::assertTrue(method_exists($class,$method)); $reports[$name]=['id'=>$name,'status'=>'passed'];
            }
        }
        // Synthetic reports test only the rejection protocol. Conductor checks real executed JUnit IDs.
        $reports=array_values($reports); self::assertCount(count($group['requiredCaseIds']),Qa::validateCases($reports,$group));
        $bad=[[],[...$reports,$reports[0]]];
        foreach(array_keys($reports) as $i) {
            $missing=$reports; unset($missing[$i]); $bad[]=array_values($missing);
            foreach(['skipped','failed'] as $status) { $changed=$reports; $changed[$i]['status']=$status; $bad[]=$changed; }
        }
        foreach($bad as $report) {
            try { Qa::validateCases($report,$group); self::fail('Missing/skipped/failed/empty/duplicate report accepted'); }
            catch(\RuntimeException) { self::assertTrue(true); }
        }
        $receipt=json_decode(file_get_contents(__DIR__.'/evidence/P3.1-reuse.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(7,$receipt['sources']);
        foreach($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']); self::assertNotEmpty($source['reason']); self::assertNotEmpty($source['license']);
            self::assertContains($source['decision'],['adapt','reject','reuse-as-is','dependency']);
            foreach($source['targetPaths'] as $path) { self::assertFileExists($root.'/'.$path); }
        }
    }
}
