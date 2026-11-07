<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Blobs;
use KnowKit\Adapter\LocalBlob\{LocalBlobStore,PrivateFilesystem};
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:blobs')]
final class LedgerTest extends TestCase
{
    private static function failure(callable $call,string $code): void
    { try { $call(); self::fail('Expected '.$code); } catch (D\DomainFailure $e) { self::assertSame($code,$e->errorCode); } }
    public function testConcurrentIdenticalWritersUseOneImmutableAcceptedIdentity(): void
    {
        BlobFixture::run(function($f) {
            $role=$f->catalog->db->pdo->query('SELECT current_user')->fetchColumn(); $workers=[];
            try {
                foreach ([301,302] as $id) {
                    $pipes=[]; $p=proc_open([PHP_BINARY,__DIR__.'/worker.php',$f->catalog->db->schema,$role,$f->root,(string)$id],
                        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                    self::assertIsResource($p); stream_set_timeout($pipes[1],15); $workers[]=[$p,$pipes];
                }
                foreach ($workers as [$p,$pipes]) { self::assertSame("READY\n",fgets($pipes[1])); }
                self::assertSame(2,$f->catalog->count('resource_writers')); self::assertSame('reserved',$f->row()['state']);
                foreach ($workers as [$p,$pipes]) { fwrite($pipes[0],"GO\n"); fflush($pipes[0]); }
                $answers=[];
                foreach ($workers as [$p,$pipes]) {
                    $answer=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR); self::assertArrayNotHasKey('error',$answer); $answers[]=$answer;
                    fclose($pipes[0]); fclose($pipes[1]); self::assertSame('',stream_get_contents($pipes[2])); fclose($pipes[2]); self::assertSame(0,proc_close($p));
                }
                $workers=[];
                self::assertNotSame($answers[0]['pid'],$answers[1]['pid']); self::assertSame($answers[0]['digest'],$answers[1]['digest']); self::assertSame($answers[0]['version'],$answers[1]['version']);
                self::assertSame(1,$f->catalog->count('resource_ledger')); self::assertSame('accepted',$f->row()['state']);
                self::assertSame(0,(int)$f->catalog->db->execute("SELECT count(*) FROM resource_writers WHERE state<>'closed'")->fetchColumn());
                $ref=$f->store->putImmutable($f->reservation(),new Bytes('abc')); self::assertSame('abc',BlobFixture::read($f->store->open($ref)));
            } finally {
                foreach ($workers as [$p,$pipes]) { if (is_resource($p)) { proc_terminate($p,9); foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } proc_close($p); } }
            }
        });
    }
    public function testObjectBeforeMetadataTimeoutNeedsQuiescenceAndVerifiedReconciliation(): void
    {
        BlobFixture::run(function($f) {
            $l=$f->ledger; $callbacks=[];
            foreach (['inspectWriter','finalizeIdentity','closeWriter','markUncertain','requireAccepted','listUnreferenced','quarantine','recoveryRef','reconcileWriter'] as $name) { $callbacks[$name]=$l->$name(...); }
            $callbacks['closeWriter']=static function() { throw new D\DomainFailure(D\Outcome::Unavailable,'metadata_timeout'); };
            $store=new LocalBlobStore($f->catalog->scope()->tenantId,$f->root,'local-v1',100,$callbacks,static function() {},$l->deletionBinding(...),$f->catalog->ids,$f->catalog->clock,PortFixture::validator());
            $r=$l->reserveWriter($f->reservation());
            self::failure(fn()=>$store->putImmutable($r,new Bytes('abc')),'metadata_timeout');
            self::assertSame('unknown',$f->row()['state']); self::assertSame(hash('sha256','abc'),$f->row()['digest']);
            $key=$f->root.'/'.$f->row()['storage_key']; self::assertSame('abc',file_get_contents($key));
            self::failure(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting'"),'storage_conflict');
            $f->catalog->db->execute("UPDATE resource_writers SET started_at=started_at");
            self::failure(fn()=>$l->reserveWriter($r),'writer_uncertain');
            self::failure(fn()=>$l->reconcileWriter($r,static fn()=>false,static fn()=>true),'writer_uncertain');
            self::failure(fn()=>$l->reconcileWriter($r,static fn()=>true,static fn()=>false),'writer_uncertain');
            self::assertSame('unknown',$f->row()['state']);
            $proof=function() use($f,$r) {
                self::assertFalse($f->catalog->db->pdo->inTransaction());
                $fs=new PrivateFilesystem($f->root); $h=$fs->lock(BlobFixture::id(1).'/'.BlobFixture::id(2).'/.locks/'.$r->resource->id->value);
                flock($h,LOCK_UN); fclose($h); return true; // put returned and closed all handles; no runner remains.
            };
            $ref=$l->reconcileWriter($r,$proof,static fn()=>hash_file('sha256',$key)===hash('sha256','abc'));
            self::assertInstanceOf(D\BlobRef::class,$ref); self::assertSame('accepted',$f->row()['state']); self::assertSame('abc',BlobFixture::read($f->store->open($ref)));
        });
    }
    public function testPersistenceBoundariesRejectForgedReservationFencesEpochsAndDeletingReuse(): void
    {
        BlobFixture::run(function($f) {
            $r=$f->ledger->reserveWriter($f->reservation());
            $wrong=new D\BlobWriteReservation($r->resource,$r->sourceId,$r->writerId,new D\Decimal('1'),$r->sourceEpoch,null,null,$r->stagingId);
            self::failure(fn()=>$f->ledger->inspectWriter($wrong),'writer_binding_mismatch');
            $wrong=new D\BlobWriteReservation($r->resource,$r->sourceId,$r->writerId,$r->fence,new D\Decimal('1'),null,null,$r->stagingId);
            self::failure(fn()=>$f->ledger->inspectWriter($wrong),'source_changed');
            $foreign=new D\BlobWriteReservation(new D\ResourceRef(new D\TenantId(BlobFixture::id(9)),'resource',$r->resource->id),$r->sourceId,$r->writerId,$r->fence,$r->sourceEpoch,null,null,$r->stagingId);
            self::failure(fn()=>$f->ledger->reserveWriter($foreign),'scope_mismatch');
            $ref=$f->store->putImmutable($r,new Bytes('abc'));
            self::failure(fn()=>$f->catalog->db->execute("UPDATE resource_ledger SET state='deleting'"),'storage_conflict');
            // Model the deletion coordinator releasing the accepted reference under its guard.
            $f->catalog->db->execute("UPDATE resource_ledger SET ref_count=0,state='deleting'");
            self::failure(fn()=>$f->ledger->reserveWriter($f->reservation(300,302)),'resource_deleting');
            self::failure(fn()=>$f->store->open($ref),'blob_not_accepted');
        });
    }
    public function testInterruptedPromotionRecoversOnlyAfterRunnerQuiescenceWithoutOverwrite(): void
    {
        foreach ([false,true] as $linked) {
            BlobFixture::run(function($f) use($linked) {
                $r=$f->ledger->reserveWriter($f->reservation()); $fs=new PrivateFilesystem($f->root);
                $stage=$f->ledger->stagingKey($r); $digest=new D\Sha256(hash('sha256','abc')); $canonical=$f->ledger->canonicalKey($r,$digest);
                $h=$fs->open($stage,true); fwrite($h,'abc'); $fs->syncFile($h); fclose($h);
                $f->ledger->finalizeIdentity($r,$digest,new D\Uint64('3'));
                if ($linked) { $fs->directory(dirname($canonical),true); link($fs->path($stage),$fs->path($canonical)); }
                $f->ledger->markUncertain($r);
                self::failure(fn()=>$f->store->reconcileWriter($r,static fn()=>false),'writer_uncertain');
                self::assertFileExists($fs->path($stage)); self::assertSame('unknown',$f->row()['state']);
                $ref=$f->store->reconcileWriter($r,static fn()=>true); // Synthetic runner has terminated and all handles above are closed.
                self::assertSame('accepted',$f->row()['state']); self::assertFileDoesNotExist($fs->path($stage));
                self::assertSame('abc',BlobFixture::read($f->store->open($ref))); clearstatcache(); self::assertSame(1,lstat($f->path($ref))['nlink']);
            });
        }
    }
    public function testAcceptedReferenceRequiresExactlyOneMatchingLedgerIdentity(): void
    {
        BlobFixture::run(function($f) {
            $ref=$f->put();
            $wrong=new D\BlobRef($ref->tenantId,$ref->sourceId,$ref->digest,$ref->byteLength,'other-storage',$ref->objectVersion,$ref->ownerKind);
            self::failure(fn()=>$f->ledger->requireAccepted($wrong),'blob_store_mismatch');
            $f->catalog->db->execute("INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state) SELECT tenant_id,?,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,'accepted' FROM resource_ledger WHERE id=?",
                [BlobFixture::id(310),BlobFixture::id(300)]);
            self::failure(fn()=>$f->store->open($ref),'blob_not_accepted');
        });
    }
}
