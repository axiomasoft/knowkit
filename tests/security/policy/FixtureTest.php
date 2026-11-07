<?php
declare(strict_types=1);
namespace KnowKit\Tests\Security\Policy;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('security:policy')]
final class FixtureTest extends TestCase
{
    public function testVersionProvenanceDigestOutcomesAndEveryRequiredCaseBinding(): void
    {
        $root=PolicyFixture::root(); $manifest=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/security-policy.json'),true,512,JSON_THROW_ON_ERROR);
        (new \KnowKit\Tooling\Qa($root))->fixtures($manifest);
        $fixture=PolicyFixture::cases();
        self::assertSame('security-policy-fixtures:1',$fixture['schemaVersion']);
        self::assertSame($manifest['fixtureVersion'],$fixture['version']); self::assertNotEmpty($fixture['versionRationale']);
        self::assertNotEmpty($fixture['rights']['license']); self::assertNotEmpty($fixture['rights']['provenance']);
        self::assertSame($fixture['payload']['sha256'],hash('sha256',$fixture['payload']['utf8']));
        self::assertSame($fixture['outcomes'],$manifest['fixtures'][0]['expected']);
        foreach (['R02-A01','R02-A02','R12-A01','R12-A02','R18-A01','R18-A02','R19-A01','R19-A02','DT-R12','POLICY-EXPIRED','POLICY-AUDIENCE'] as $id) {
            self::assertContains($id,$manifest['requiredCaseIds']); self::assertNotEmpty($manifest['testCaseMap'][$id]);
        }
        foreach ($manifest['testCaseMap'] as $mapping) {
            foreach ($mapping as $name) {
                [$class,$method]=explode('::',$name); $short=substr($class,strrpos($class,'\\')+1);
                require_once __DIR__.'/'.$short.'.php'; self::assertTrue(method_exists($class,$method),$name);
            }
        }
        $receipt=json_decode(file_get_contents(__DIR__.'/evidence/P2.3-reuse.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('reuse-receipt:1',$receipt['schemaVersion']); self::assertCount(7,$receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['sourceRead']); self::assertTrue($source['hashVerified']);
            self::assertContains($source['decision'],['adapt','reuse','reject']); self::assertNotEmpty($source['reason']);
        }
    }
}
