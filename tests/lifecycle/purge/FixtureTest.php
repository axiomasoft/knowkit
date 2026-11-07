<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Purge;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:purge')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndEveryRequiredCaseHaveExecutableAssertions(): void
    {
        $root = dirname(__DIR__,3);
        $manifest = json_decode(file_get_contents($root.'/tests/fixtures/check-groups/lifecycle-purge.json'),true,64,JSON_THROW_ON_ERROR);
        self::assertSame('check-group:1',$manifest['schemaVersion']);
        self::assertSame('lifecycle:purge',$manifest['group']);
        self::assertSame('1.0.0',$manifest['fixtureVersion']);
        $fixture = json_decode(file_get_contents($root.'/'.$manifest['fixtures'][0]['path']),true,64,JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256',$root.'/'.$manifest['fixtures'][0]['path']),$manifest['fixtures'][0]['sha256']);
        $ids = array_column($fixture['cases'],'id'); self::assertCount(count(array_unique($ids)),$ids);
        foreach (['R31-A01','R31-A02','DT-I15'] as $id) { self::assertContains($id,$manifest['requiredCaseIds']); }
        foreach ($ids as $id) { self::assertContains($id,$manifest['requiredCaseIds']); }
        require_once __DIR__.'/AttemptsTest.php'; require_once __DIR__.'/InventoryTest.php'; require_once __DIR__.'/InvariantsTest.php';
        foreach ($manifest['requiredCaseIds'] as $id) {
            self::assertArrayHasKey($id,$manifest['testCaseMap']);
            foreach ($manifest['testCaseMap'][$id] as $method) {
                [$class,$name] = explode('::',$method); self::assertTrue(method_exists($class,$name),$method);
                $reflection = new \ReflectionMethod($class,$name);
                self::assertSame('lifecycle:purge',(new \ReflectionClass($class))->getAttributes(Group::class)[0]->getArguments()[0]);
                if (in_array($id,$ids,true)) {
                    $lines = file($reflection->getFileName());
                    $body = implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
                    self::assertStringContainsString("Fixture::expected('".$id."'",$body);
                }
            }
        }
    }
}
