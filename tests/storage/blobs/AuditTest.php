<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use KnowKit\Application\Catalog\RemoveKnowledgeBase;
use KnowKit\Application\Resources\CanonicalContent;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:blobs')]
final class AuditTest extends TestCase
{
    public function testCurrentOwnerDenyAndUnknownOverrideStaleLocalAllowBeforeEveryBuffer(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put(); $stream=$f->store->open($ref); $calls=$f->catalog->owner->calls;
            foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
                $f->catalog->owner->decision=$decision;
                try { $stream->read(1); self::fail('Stale local allow authorized bytes'); }
                catch (D\DomainFailure $e) { self::assertSame($decision===D\Decision::Deny?D\Outcome::Denied:D\Outcome::Unknown,$e->outcome); }
                self::assertTrue($f->catalog->owner->staleLocalAllow);
                try { $f->store->exists($ref); self::fail('Existence disclosed without current owner'); }
                catch (D\DomainFailure $e) { self::assertSame($decision===D\Decision::Deny?D\Outcome::Denied:D\Outcome::Unknown,$e->outcome); }
            }
            self::assertGreaterThan($calls,$f->catalog->owner->calls); $f->catalog->owner->decision=D\Decision::Allow;
            self::assertSame('a',$stream->read(1));
            $f->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=1,retention_hold=true");
            try { $stream->read(1); self::fail('Revoked held source disclosed'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied,$e->outcome); }
            $stream->close(); self::assertSame('abc',file_get_contents($f->path($ref)));
        });
    }
    public function testKnowledgeBaseRemovalIntendsManagedDeletionAndOnlyDetachesExternalObjects(): void
    {
        BlobFixture::run(function($f) {
            $owned=$f->put(); $c=$f->catalog; $c->resource(310,2,1,'external');
            $remove=new RemoveKnowledgeBase($c->repo,$c->repo->removeKnowledgeBase(...),$c->tx,$c->authority);
            $tasks=$remove->remove($c->scope(),$c->context('blob-remove','0'));
            self::assertCount(1,$tasks); self::assertSame(0,$c->count('source_memberships'));
            $inventory=json_decode($c->db->execute('SELECT required_resources_json FROM deletion_tasks')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            self::assertCount(1,$inventory); self::assertSame(BlobFixture::id(300),$inventory[0]['resourceId']);
            self::assertSame('accepted',$c->db->execute("SELECT state FROM resource_ledger WHERE owner_kind='external'")->fetchColumn());
            self::assertFileExists($f->path($owned)); self::assertSame(0,$c->count('deletion_attempts'));
            try { $f->store->open($owned); self::fail('Detached KB retained read authority'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied,$e->outcome); }
        });
    }
    private static function canonical(BlobFixture $f,D\BlobRef $blob): array
    {
        $source=new D\ExactSourceRef($blob->tenantId,$blob->sourceId,new D\SourceVersionId(BlobFixture::id(500)),new D\SourceDigest($blob->digest->value));
        $identity=['tenantId'=>$source->tenantId->value,'sourceId'=>$source->sourceId->value,'sourceVersionId'=>$source->sourceVersionId->value,
            'sourceDigest'=>$source->sourceDigest->value,'documentRevisionId'=>BlobFixture::id(501),'irDigest'=>hash('sha256','synthetic canonical IR v1'),
            'span'=>['startByte'=>0,'endByte'=>3],'artifactVersion'=>$blob->objectVersion];
        $reader=new CanonicalContent($f->store,static function($exact,$artifact) use($source,$blob) {
            $source->assertSame($exact); if ($artifact!=$blob) { throw new D\DomainFailure(D\Outcome::Incompatible,'immutable_conflict'); }
        },100);
        return [$source,$identity,$reader];
    }
    public function testProjectionRebuildUsesCanonicalContentWithoutChangingSourceRevisionOrCitation(): void
    {
        BlobFixture::run(function($f) {
            $blob=$f->put(); [$source,$metadata,$reader]=self::canonical($f,$blob);
            // A disposable search projection is a harness; no production retrieval workflow is claimed.
            $index=['abc'=>['identity'=>$metadata,'text'=>$reader->read($source,$blob)]]; $before=$index['abc'];
            $index=[]; self::assertArrayNotHasKey('abc',$index); self::assertTrue($f->store->exists($blob));
            $canonical=$reader->read($source,$blob); $index[$canonical]=['identity'=>$metadata,'text'=>$canonical];
            self::assertSame($before,$index['abc']); self::assertSame($metadata,$index['abc']['identity']);
            self::assertSame('abc',$index['abc']['text']); self::assertSame('accepted',$f->row()['state']);
        });
    }
    public function testForgedIndexTextCannotReplaceMissingOrCorruptCanonicalObject(): void
    {
        BlobFixture::run(function($f) {
            $blob=$f->put(); [$source,$identity,$reader]=self::canonical($f,$blob);
            $backendHit=['identity'=>$identity,'text'=>'credible forged backend text'];
            self::assertSame('abc',$reader->read($source,$blob)); self::assertNotSame($backendHit['text'],$reader->read($source,$blob));
            chmod($f->path($blob),0600); file_put_contents($f->path($blob),'abx');
            foreach (['corrupt','missing'] as $state) {
                if ($state==='missing') { unlink($f->path($blob)); }
                $disclosed=[];
                try { $disclosed[]=$reader->read($source,$blob); self::fail('Backend text substituted for canonical artifact'); }
                catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Incompatible,$e->outcome); }
                self::assertSame([],$disclosed); self::assertSame('credible forged backend text',$backendHit['text']);
            }
        });
    }
}
