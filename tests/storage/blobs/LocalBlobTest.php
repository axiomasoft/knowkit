<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use KnowKit\Adapter\LocalBlob\PrivateFilesystem;
use KnowKit\Application\Resources\Upload;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:blobs')]
final class LocalBlobTest extends TestCase
{
    private static function fails(callable $call,string $code,?D\Outcome $outcome=null): void
    {
        try { $call(); self::fail('Expected '.$code); }
        catch (D\DomainFailure $e) { self::assertSame($code,$e->errorCode); if ($outcome!==null) { self::assertSame($outcome,$e->outcome); } }
    }
    public function testReservationExistsBeforeFirstByteAndFinalizesOnce(): void
    {
        BlobFixture::run(function($f) {
            $r=$f->reservation();
            self::fails(fn()=>$f->store->putImmutable($r,new Bytes('abc')),'reservation_mismatch');
            self::assertSame(0,$f->catalog->count('resource_ledger'));
            $put=new Upload($f->store,$f->ledger->reserveWriter(...),$f->ledger->requireAccepted(...),$f->ledger->markUncertain(...));
            $stream=new Bytes('abc',function() use($f) {
                self::assertFalse($f->catalog->db->pdo->inTransaction());
                self::assertSame('reserved',$f->row()['state']); self::assertNull($f->row()['digest']);
                self::assertSame('active',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
                self::fails(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting',digest=repeat('a',64),byte_length=3"),'storage_conflict');
            });
            $ref=$put->put($r,$stream); self::assertTrue($stream->closed);
            self::assertSame('accepted',$f->row()['state']); self::assertSame(BlobFixture::id(1300),$f->row()['staging_id']);
            self::assertSame('1',(string)$f->row()['ref_count']);
            self::fails(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting'"),'storage_conflict');
            self::assertSame(hash('sha256','abc'),$ref->digest->value); self::assertSame('3',$ref->byteLength->value);
            self::assertSame('abc',BlobFixture::read($f->store->open($ref)));
            $row=$f->catalog->db->row('SELECT state,closed_at FROM resource_writers'); self::assertSame('closed',$row['state']); self::assertNotNull($row['closed_at']);
            self::fails(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET digest=repeat('e',64)"),'storage_conflict');
            self::assertEquals($ref,$f->store->putImmutable($r,new Bytes('abc')));
            self::fails(fn()=>$f->store->putImmutable($r,new Bytes('different')),'blob_digest_mismatch');
            self::assertSame('abc',file_get_contents($f->path($ref)));
        });
    }
    public function testWrongDigestSizeLimitAndBrokenStreamFailClosed(): void
    {
        BlobFixture::run(function($f) {
            $r=$f->ledger->reserveWriter($f->reservation(300,301,true));
            self::fails(fn()=>$f->store->putImmutable($r,new Bytes('abd')),'blob_digest_mismatch');
            self::assertSame('unknown',$f->row()['state']); self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            $r=$f->ledger->reserveWriter($f->reservation(310,311));
            self::fails(fn()=>$f->makeStore(limit:2)->putImmutable($r,new Bytes('abc')),'blob_size_limit');
            self::assertSame('unknown',$f->row(310)['state']);
            $r=$f->ledger->reserveWriter($f->reservation(320,321));
            $bad=new class implements \KnowKit\Port\ReadableStream {
                public function read(int $maxBytes): string { return ''; } public function eof(): bool { return false; } public function close(): void {}
            };
            self::fails(fn()=>$f->store->putImmutable($r,$bad),'stream_contract');
            self::assertSame('unknown',$f->row(320)['state']);
            $base=$f->reservation(330,331,true);
            $known=new D\BlobWriteReservation($base->resource,$base->sourceId,$base->writerId,$base->fence,$base->sourceEpoch,$base->digest,$base->byteLength,null);
            $ref=$f->store->putImmutable($f->ledger->reserveWriter($known),new Bytes('abc'));
            self::assertNull($f->row(330)['staging_id']); self::assertSame('abc',BlobFixture::read($f->store->open($ref)));
        });
    }
    public function testTraversalSymlinkHardlinkAndForeignTenantAreRejected(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put(); $fs=new PrivateFilesystem($f->root);
            foreach (['../escape','a/../../escape','/absolute','a/%2e%2e/b'] as $key) { self::fails(fn()=>$fs->path($key),'unsafe_blob_path'); }
            $foreign=new D\BlobRef(new D\TenantId(BlobFixture::id(9)),$ref->sourceId,$ref->digest,$ref->byteLength,$ref->storageId,$ref->objectVersion,'owned');
            self::fails(fn()=>$f->store->open($foreign),'scope_mismatch',D\Outcome::Denied);
            self::fails(fn()=>$f->store->exists($foreign),'scope_mismatch');
            $path=$f->path($ref); rename($path,$f->root.'/outside'); symlink($f->root.'/outside',$path);
            self::fails(fn()=>$f->store->open($ref),'unsafe_blob_path'); unlink($path);
            link($f->root.'/outside',$path); self::fails(fn()=>$f->store->open($ref),'unsafe_blob_path'); unlink($path); rename($f->root.'/outside',$path);
            $dir=dirname($path); rename($dir,$dir.'-saved'); symlink($dir.'-saved',$dir);
            self::fails(fn()=>$f->store->open($ref),'unsafe_blob_path'); unlink($dir); rename($dir.'-saved',$dir);
            self::assertSame('abc',file_get_contents($path));
        });
    }
    public function testMissingAndCorruptObjectsNeverReleasePartialBytes(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put(); $stream=$f->store->open($ref); chmod($f->path($ref),0600); file_put_contents($f->path($ref),'abx');
            self::fails(fn()=>$stream->read(1),'blob_digest_mismatch'); $stream->close();
            self::fails(fn()=>$f->store->open($ref),'blob_digest_mismatch');
            file_put_contents($f->path($ref),'long'); self::fails(fn()=>$f->store->open($ref),'blob_length_mismatch');
            unlink($f->path($ref)); self::assertFalse($f->store->exists($ref)); self::fails(fn()=>$f->store->open($ref),'blob_missing');
        });
    }
    public function testFileAndDirectoryFsyncFailureNeverReportsDurabilitySuccess(): void
    {
        foreach (['file','directory'] as $kind) {
            BlobFixture::run(function($f) use($kind) {
                $r=$f->ledger->reserveWriter($f->reservation());
                $store=$f->makeStore(static fn($h,string $type): bool=>$type!==$kind && fsync($h));
                self::fails(fn()=>$store->putImmutable($r,new Bytes('abc')),'blob_durability_failed',D\Outcome::Unavailable);
                self::assertSame('unknown',$f->row()['state']);
                self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
                self::fails(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting',digest=repeat('a',64),byte_length=3"),'storage_conflict');
            });
        }
    }
    public function testOrphanStagingIsQuarantinedAndInventoryGrantsNoAuthority(): void
    {
        BlobFixture::run(function($f) {
            $fs=new PrivateFilesystem($f->root); $key=BlobFixture::id(1).'/'.BlobFixture::id(2).'/staging/'.BlobFixture::id(1500);
            $h=$fs->open($key,true); fwrite($h,'orphan'); $fs->syncFile($h); fclose($h);
            $page=$f->store->listUnreferenced(new D\UtcInstant('2026-11-07T00:00:00Z'),new D\Cursor($f->catalog->scope()->tenantId));
            self::assertCount(1,$page->resources); $row=$f->catalog->db->row('SELECT * FROM resource_ledger');
            self::assertSame('unknown',$row['state']); self::assertTrue($row['retention_hold']); self::assertNull($row['digest']);
            self::assertFileExists($fs->path($key)); self::assertSame($key,$row['storage_key']);
            self::assertCount(1,$f->store->listUnreferenced(new D\UtcInstant('2026-11-07T00:00:00Z'),new D\Cursor($f->catalog->scope()->tenantId))->resources);
            self::fails(fn()=>$f->store->listUnreferenced(new D\UtcInstant('2026-11-07T00:00:00Z'),new D\Cursor(new D\TenantId(BlobFixture::id(9)))),'scope_mismatch');
            self::fails(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting',digest=repeat('a',64),byte_length=6"),'storage_conflict');
        });
    }
    public function testEmptyAndMultiChunkStreamsHaveExactBoundedIdentity(): void
    {
        BlobFixture::run(function($f) {
            $empty=$f->put(bytes:''); self::assertSame('0',$empty->byteLength->value); self::assertSame(hash('sha256',''),$empty->digest->value);
            self::assertSame('',BlobFixture::read($f->store->open($empty)));
            $bytes=str_repeat('x',131073); $bounds=[];
            $stream=new Bytes($bytes,function(int $bound) use(&$bounds) { $bounds[]=$bound; });
            $r=$f->ledger->reserveWriter($f->reservation(310,311)); $ref=$f->store->putImmutable($r,$stream);
            self::assertSame([65536,65536,65536],$bounds); self::assertSame(hash('sha256',$bytes),$ref->digest->value);
            $read=$f->store->open($ref); $actual='';
            while (!$read->eof()) { $chunk=$read->read(65536); self::assertLessThanOrEqual(65536,strlen($chunk)); $actual.=$chunk; }
            $read->close(); self::assertSame($bytes,$actual);
        });
    }
    public function testFinalFileAndPromotionDirectoryFsyncFailureLeaveRecoverableObligations(): void
    {
        foreach (['final-file','promotion-directory'] as $failure) {
            BlobFixture::run(function($f) use($failure) {
                $r=$f->ledger->reserveWriter($f->reservation()); $key=$f->root.'/'.$f->ledger->canonicalKey($r,new D\Sha256(hash('sha256','abc')));
                $sync=static function($h,string $kind) use($failure,$key): bool {
                    clearstatcache();
                    if (is_file($key) && (($failure==='final-file' && $kind==='file') || ($failure==='promotion-directory' && $kind==='directory'))) { return false; }
                    return fsync($h);
                };
                self::fails(fn()=>$f->makeStore($sync)->putImmutable($r,new Bytes('abc')),'blob_durability_failed');
                self::assertSame('unknown',$f->row()['state']); self::assertFileExists($key);
                self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
                $ref=$f->store->reconcileWriter($r,static fn()=>true); self::assertSame('accepted',$f->row()['state']);
                self::assertSame('abc',BlobFixture::read($f->store->open($ref)));
            });
        }
    }
}
