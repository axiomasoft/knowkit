<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:restore')]
final class FixtureTest extends TestCase
{
    public function testVersionedManifestBindsEveryRequiredExecutableCaseAndRejectsMissingOrSkippedCases(): void
    {
        $root=dirname(__DIR__,3);
        $m=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/lifecycle-restore.json'),true,64,JSON_THROW_ON_ERROR);
        (new Qa($root))->fixtures($m);
        self::assertSame('restore-fixtures:1',Fixture::data()->get('schemaVersion'));
        $ids=[]; foreach (Fixture::data()->get('cases') as $c) { $ids[]=$c->get('id'); }
        self::assertSame($ids,$m['requiredCaseIds']); self::assertContains('R32-A01',$ids); self::assertContains('R32-A02',$ids);
        require_once __DIR__.'/RestoreTest.php'; require_once __DIR__.'/DurabilityTest.php'; require_once __DIR__.'/ProjectionTest.php';
        $executed=[];
        foreach ($ids as $id) {
            self::assertArrayHasKey($id,$m['testCaseMap']);
            foreach ($m['testCaseMap'][$id] as $method) {
                [$class,$name]=explode('::',$method); $reflection=new \ReflectionMethod($class,$name);
                $lines=file($reflection->getFileName()); $body=implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
                self::assertStringContainsString("Fixture::expected('".$id."'",$body);
                self::assertSame('lifecycle:restore',(new \ReflectionClass($class))->getAttributes(Group::class)[0]->getArguments()[0]);
                $executed[$method]=['id'=>$method,'status'=>'passed'];
            }
        }
        self::assertCount(count($ids),Qa::validateCases(array_values($executed),$m));
        foreach ([[],array_slice(array_values($executed),1),[['id'=>array_key_first($executed),'status'=>'skipped']]] as $bad) {
            try { Qa::validateCases($bad,$m); self::fail('Missing/skipped required case accepted'); }
            catch (\RuntimeException $e) { self::assertNotSame('',$e->getMessage()); }
        }
    }
}
