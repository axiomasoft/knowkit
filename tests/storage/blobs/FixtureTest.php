<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:blobs')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixtureDigestsProvenanceOutcomesAndCaseBindings(): void
    {
        $root=dirname(__DIR__,3);
        $manifest=json_decode(file_get_contents($root.'/tests/fixtures/check-groups/storage-blobs.json'),true,512,JSON_THROW_ON_ERROR);
        $fixture=json_decode(file_get_contents($root.'/tests/fixtures/blobs/v1/cases.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('check-group:1',$manifest['schemaVersion']); self::assertSame('blob-fixtures:1',$fixture['schemaVersion']);
        self::assertSame($manifest['fixtureVersion'],$fixture['version']); self::assertNotEmpty($fixture['versionRationale']);
        self::assertNotEmpty($fixture['rights']['license']); self::assertNotEmpty($fixture['rights']['provenance']);
        $entry=$manifest['fixtures'][0]; self::assertSame($entry['sha256'],hash_file('sha256',$root.'/'.$entry['path']));
        $bytes=base64_decode($fixture['payload']['base64'],true); self::assertSame('abc',$bytes);
        self::assertSame($fixture['payload']['sha256'],hash('sha256',$bytes)); self::assertSame($fixture['payload']['byteLength'],(string)strlen($bytes));
        foreach ($entry['expected'] as $name=>$value) {
            self::assertSame($value,$fixture['payload'][$name]??$fixture['outcomes'][$name]);
        }
        foreach (['BLOB-DIGEST','BLOB-CONCURRENT','BLOB-PATH','BLOB-TIMEOUT','BLOB-MISSING','BLOB-ORPHAN','BLOB-UNSUPPORTED-DELETE','R02-A01','R02-A02','R03-A01','R03-A02','DT-I06','DT-R13'] as $id) {
            self::assertContains($id,$manifest['requiredCaseIds']); self::assertNotEmpty($manifest['testCaseMap'][$id]);
        }
        self::assertSame(count($manifest['requiredCaseIds']),count(array_unique($manifest['requiredCaseIds'])));
        foreach ($manifest['testCaseMap'] as $cases) {
            foreach ($cases as $case) {
                [$class,$method]=explode('::',$case); $short=substr($class,strrpos($class,'\\')+1);
                require_once __DIR__.'/'.$short.'.php'; self::assertTrue(method_exists($class,$method),$case);
            }
        }
        BlobFixture::run(function($f) use($fixture,$bytes) {
            $ref=$f->put(bytes:$bytes); self::assertSame($fixture['payload']['sha256'],$ref->digest->value);
            self::assertSame($fixture['payload']['byteLength'],$ref->byteLength->value);
        });
    }
}
