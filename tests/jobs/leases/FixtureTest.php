<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,StrictJson};
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('jobs:leases')]
final class FixtureTest extends TestCase
{
    public function testVersionedDigestsClosedCheckpointAndRequiredCaseReportsFailClosed(): void
    {
        $root=dirname(__DIR__,3);
        $group=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/jobs-leases.json'),true,512,JSON_THROW_ON_ERROR);
        $cases=json_decode(file_get_contents(__DIR__.'/fixtures/v1/cases.json'),true,512,JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($group);
        self::assertSame('jobs-leases-fixtures:1',$cases['schemaVersion']); self::assertSame($group['fixtureVersion'],$cases['fixtureVersion']);
        self::assertSame($group['requiredCaseIds'],array_column($cases['cases'],'id'));
        self::assertSame(hash('sha256','source'),$cases['digests']['source']);
        self::assertSame(hash('sha256','artifact-310'),$cases['digests']['artifact']);
        self::assertSame(hash('sha256','result'),$cases['digests']['result']);
        $wire=StrictJson::decode(file_get_contents(__DIR__.'/fixtures/v1/checkpoint.json'));
        $manifest=new D\JobCheckpointManifest($wire);
        self::assertSame($cases['digests']['checkpoint'],$manifest->digest()->value);
        self::assertSame($cases['digests']['inventory'],$wire->get('outputDigest'));
        Fixture::run(function($f) use($cases): void {
            self::assertSame(
                '00c91fc8d4ba309d8d12c2b773d193180c373c178650d3573ecee19e9b7ab978',
                $cases['digests']['processing']
            );
            self::assertSame('732f6b2c615b5e62f4159b32e78236bd109fe97e711339b2e918f2ee95507ddc',$f->processing->digest()->value);
            $f->enqueue(); $lease=$f->lease(); $m=$f->manifest($lease);
            $reader=static fn()=>new class implements \KnowKit\Port\ReadableStream {
                private bool $read=false; private bool $end=false;
                public function read(int $maxBytes): string { if ($this->read) { $this->end=true; return ''; } $this->read=true; return 'artifact-310'; }
                public function eof(): bool { return $this->end; }
                public function close(): void {}
            };
            $verifier=new \KnowKit\Application\Jobs\VerifiedCheckpoints(static fn()=>Jcs::canonicalize($m->toWire()),$reader);
            self::assertEquals($m,$verifier->load($lease,$m->checkpoint())); // fread-style EOF becomes known only after the empty read
            foreach (['[]','{'] as $invalidBytes) {
                $invalid=new \KnowKit\Application\Jobs\VerifiedCheckpoints(static fn()=>$invalidBytes,$reader);
                try { $invalid->load($lease,$m->checkpoint()); self::fail('Malformed manifest accepted'); }
                catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Incompatible,$e->outcome); self::assertSame('invalid_checkpoint',$e->errorCode); }
            }
        });
        foreach ([$wire->with('outputDigest',str_repeat('a',64)),$wire->with('path','../escape'),
            $wire->with('resources',[]),$wire->with('resources',[$wire->get('resources')[0],$wire->get('resources')[0]])] as $invalid) {
            try { new D\JobCheckpointManifest($invalid); self::fail('Malformed checkpoint accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('invalid_checkpoint',$e->errorCode); }
        }
        $mandatory=['ACQUIRE-TWO','STALE-TOKEN-TAKEOVER','CANCEL-EXTRACTION','PROVIDER-TIMEOUT-ACCEPTED','KILL-BEFORE-CHECKPOINT','KILL-AFTER-COMMIT',
            'R11-A01','R11-A02','R17-A01','R17-A02','DT-I03','DT-R09','DT-Q01'];
        foreach ($mandatory as $id) { self::assertContains($id,$group['requiredCaseIds']); }
        $executed=[];
        foreach ($group['testCaseMap'] as $names) {
            foreach ($names as $name) {
                [$class,$method]=explode('::',$name); $short=substr($class,strrpos($class,'\\')+1);
                require_once __DIR__.'/'.$short.'.php'; self::assertTrue(method_exists($class,$method));
                $executed[$name]=['id'=>$name,'status'=>'passed'];
            }
        }
        // Synthetic reports only test runner rejection; actual execution comes from conductor JUnit.
        $reports=array_values($executed); self::assertCount(count($group['requiredCaseIds']),Qa::validateCases($reports,$group));
        $skip=$reports; $skip[0]['status']='skipped'; $red=$reports; $red[0]['status']='failed';
        foreach ([[],array_slice($reports,1),[...$reports,$reports[0]],$skip,$red] as $bad) {
            try { Qa::validateCases($bad,$group); self::fail('Missing/skipped/red/empty execution accepted'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        $receipt=json_decode(file_get_contents(__DIR__.'/evidence/P2.7-reuse.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(7,$receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']);
            self::assertContains($source['decision'],['adapt','reject','reuse-as-is','dependency']);
            self::assertNotEmpty($source['reason']); self::assertNotEmpty($source['license']);
            foreach ($source['targetPaths'] as $path) { self::assertFileExists($root.'/'.$path); }
        }
    }
}
