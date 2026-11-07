<?php
declare(strict_types=1);
namespace KnowKit\Tests\Retrieval\Lexical;

use KnowKit\Domain\Contract\{HashDomain, StrictJson};
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:lexical')]
final class FixtureTest extends TestCase
{
    public function testIndependentDigestsVersionAndRequiredCases(): void
    {
        $root = LexicalFixture::root();
        $manifest = json_decode(file_get_contents($root.'/tests/fixtures/retrieval/lexical/manifest.v1.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('lexical-fixtures:1',$manifest['schemaVersion']); self::assertSame('1.0.0',$manifest['fixtureVersion']);
        self::assertNotEmpty($manifest['versionRationale']); self::assertNotEmpty($manifest['rights']['license']); self::assertNotEmpty($manifest['rights']['origin']);
        foreach ($manifest['files'] as $entry) { self::assertSame($entry['sha256'],hash_file('sha256',$root.'/'.$entry['path'])); self::assertNotEmpty($entry['expected']); }
        foreach (LexicalFixture::corpus()['documents'] as $doc) {
            self::assertSame($doc['textDigest'],hash('sha256',$doc['text']));
            $m = StrictJson::decode(json_encode($doc['chunkSetManifest'],JSON_THROW_ON_ERROR));
            self::assertSame($doc['chunkSetDigest'],HashDomain::ChunkSetManifest->digest($m));
            self::assertSame(strlen($doc['text']),$m->get('chunks')[0]->get('spans')[0]->get('byteEnd'));
        }
        $group = json_decode(file_get_contents($root.'/tests/fixtures/check-groups/retrieval-lexical.json'),true,512,JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($group);
        foreach (['R20-A01','R20-A02','R24-A01','R24-A02','R24-A03','SQL-LIKE-INPUT','KB-2026-001','CYRILLIC-CJK','NO-MATCH','ROGUE-CANDIDATE-ID'] as $id) {
            self::assertContains($id,$group['requiredCaseIds']); self::assertNotEmpty($group['testCaseMap'][$id]);
        }
        foreach ($group['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class,$name] = explode('::',$method); require_once __DIR__.'/'.substr($class,strrpos($class,'\\')+1).'.php';
                self::assertTrue(method_exists($class,$name),$method);
            }
        }
        $receipt = json_decode(file_get_contents(__DIR__.'/evidence/P2.8-reuse.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('P2.8',$receipt['item']); self::assertCount(6,$receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'],$source['observedSha256']);
            self::assertContains($source['decision'],['reuse-as-is','adapt','dependency','reject']);
            self::assertNotEmpty($source['reason']); self::assertNotEmpty($source['qualificationRef']);
            foreach ($source['targetPaths'] as $path) { self::assertFileExists($root.'/'.$path); }
        }
    }
    public function testMissingSkippedAndEmptyCasesAreRejected(): void
    {
        $manifest = ['requiredCaseIds'=>['required'],'testCaseMap'=>['required'=>['Actual::testRequired']]];
        foreach ([[],[['id'=>'Actual::different','status'=>'passed']],[['id'=>'Actual::testRequired','status'=>'skipped']],
            [['id'=>'Actual::testRequired','status'=>'failed']],[['id'=>'Actual::testRequired','status'=>'passed'],['id'=>'Actual::testRequired','status'=>'passed']]] as $cases) {
            try { Qa::validateCases($cases,$manifest); self::fail('Missing/skipped/failed/empty/duplicate case accepted'); }
            catch (\RuntimeException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame([['id'=>'required','status'=>'passed']],Qa::validateCases([['id'=>'Actual::testRequired','status'=>'passed']],$manifest));
    }
}
