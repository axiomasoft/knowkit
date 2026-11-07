<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Domain\Contract\{HashDomain,Jcs};
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('events:outbox')]
final class FixtureTest extends TestCase
{
    private function manifest(): array
    { return json_decode(file_get_contents(Fixture::root().'/tests/fixtures/check-groups/events-outbox.json'),true,512,JSON_THROW_ON_ERROR); }
    public function testVersionedManifestAndIndependentlyComputedDigests(): void
    {
        $manifest = $this->manifest(); $data = Fixture::data();
        self::assertSame('check-group:1',$manifest['schemaVersion']); self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['versionRationale']); self::assertSame('events-outbox-fixtures:1',$data->get('schemaVersion'));
        foreach ($manifest['fixtures'] as $f) { self::assertSame($f['sha256'],hash_file('sha256',Fixture::root().'/'.$f['path'])); }
        self::assertSame($manifest['requiredCaseIds'],$data->get('outcomes')->keys());
        self::assertSame(Jcs::canonicalize($data->get('event')),Fixture::event()->canonical());
        self::assertSame($data->get('payloadDigest'),HashDomain::DomainEventPayload->digest(Fixture::event()->toWire()->get('payload')));
        self::assertSame($data->get('canonicalEnvelopeSha256'),hash('sha256',Fixture::event()->canonical()));
        Fixture::outcome('FIXTURE-INTEGRITY',['digests'=>'verified']);
    }
    public function testRunnerRejectsEmptyMissingSkippedAndNestedFailure(): void
    {
        $manifest = $this->manifest();
        $ids = array_values(array_unique(array_merge(...array_values($manifest['testCaseMap']))));
        $cases = array_map(static fn(string $id): array => ['id'=>$id,'status'=>'passed'],$ids);
        self::assertCount(count($manifest['requiredCaseIds']),Qa::validateCases($cases,$manifest));
        $skipped = $cases; $skipped[0]['status'] = 'skipped'; $failed = $cases; $failed[0]['status'] = 'failed';
        foreach ([[],array_slice($cases,1),$skipped,$failed] as $bad) {
            try { Qa::validateCases($bad,$manifest); self::fail('Invalid executed inventory passed'); }
            catch (\RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
        }
        // Exercise actual child exit handling, not a fabricated success receipt.
        $qa = new Qa(Fixture::root()); $result = $qa->process([PHP_BINARY,'-r','exit(7);']);
        self::assertSame(7,$result['exit']);
        $unknown = $qa->process([PHP_BINARY,'tools/qa.php','events:outbox-missing']);
        self::assertNotSame(0,$unknown['exit']);
        Fixture::outcome('RUNNER-NEGATIVES',['missing'=>'fail','skipped'=>'fail','empty'=>'fail','nestedFailure'=>'fail']);
    }
}
