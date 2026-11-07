<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:restore')]
final class RestoreTest extends TestCase
{
    public function testBackupOlderThanDeleteReplaysBeforeReadinessAndNeverDiscloses(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->mark(); $p=$f->plan(); $r=$f->runner(); $r->start($p);
            self::assertSame('blocked',$f->serving()); $r->step($p,1);
            self::assertSame('revoked',$f->blobs->catalog->db->execute('SELECT lifecycle_state FROM sources')->fetchColumn());
            self::assertSame(1,$f->blobs->catalog->count('tombstones')); self::assertSame('blocked',$f->serving());
            self::assertSame('ready',$f->finish($p)['stage']);
            Fixture::failure(fn()=>$f->blobs->store->open($f->ref),'blob_not_accepted',D\Outcome::Denied);
            Fixture::expected('BACKUP-OLDER-DELETE',['serving'=>$f->serving(),'bytesRetained'=>is_file($f->blobs->path($f->ref)),'payload'=>'denied']);
            Fixture::expected('R32-A02',['tombstones'=>1,'payload'=>'denied','replayedSequence'=>$f->store->checkpoint($p)['sequence']]);
            self::assertSame($p->journalDigest->value,Fixture::data()->get('expectedDeleteJournalDigest'));
            self::assertSame($f->ref->digest->value,hash_file('sha256',$f->blobs->path($f->ref)));
        });
    }
    public function testTombstoneBeforeSourceRestoreBlocksLateRegistration(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->mark(9); $p=$f->plan(); $f->runner()->start($p); self::assertSame('ready',$f->finish($p)['stage']);
            $c=$f->blobs->catalog;
            Fixture::failure(fn()=>$c->source(9),'storage_conflict',D\Outcome::Incompatible);
            Fixture::expected('TOMBSTONE-BEFORE-SOURCE',['tombstones'=>$c->count('tombstones'),'restoredSource'=>'rejected']);
        });
    }
    public function testLegalHoldRetainsBytesAndMinimalAuditWithoutReadPermission(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->mark(2,true); $p=$f->plan(); $f->runner()->start($p); $f->finish($p);
            $c=$f->blobs->catalog;
            self::assertTrue((bool)$c->db->execute('SELECT retention_hold FROM sources')->fetchColumn());
            Fixture::failure(fn()=>$f->blobs->store->open($f->ref),'blob_not_accepted',D\Outcome::Denied);
            $audit=$c->db->row('SELECT reason,event_id FROM tombstones');
            self::assertSame('source_deleted',$audit['reason']);
            // Historical BlobRef remains exact; ordinary historical reading uses the same accepted/lifecycle boundary.
            Fixture::failure(fn()=>$f->blobs->ledger->requireAccepted($f->ref),'blob_not_accepted',D\Outcome::Denied);
            Fixture::expected('R32-A01',['bytesRetained'=>is_file($f->blobs->path($f->ref)),'history'=>'denied','payload'=>'denied','audit'=>'minimal']);
        });
    }
    public function testObjectWithoutMetadataBecomesUnknownObligationWithoutDeletion(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan();
            $key=$f->tenant->value.'/'.Fixture::id(2).'/sha256/'.hash('sha256','orphan').'/'.Fixture::id(700);
            $path=$f->blobs->root.'/'.$key; mkdir(dirname($path),0700,true); file_put_contents($path,'orphan'); chmod($path,0600);
            $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            $c=$f->blobs->catalog;
            self::assertSame('unknown',$c->db->execute('SELECT state FROM resource_ledger WHERE storage_key=?',[$key])->fetchColumn());
            self::assertSame(1,(int)$c->db->execute("SELECT count(*) FROM reconciliation_findings WHERE stage='objects' AND outcome='unknown_object'")->fetchColumn());
            self::assertSame(0,(int)$c->db->execute("SELECT count(*) FROM reconciliation_findings WHERE stage='ledger'")->fetchColumn());
            self::assertSame($p->inventories['ledger']->get('digest'),Fixture::inventory($f->store->scan($p,'ledger',null,100))->get('digest'));
            Fixture::expected('OBJECT-WITHOUT-METADATA',['serving'=>$f->serving(),'orphan'=>'unknown','bytesRetained'=>is_file($path)]);
        });
    }
    public function testMetadataWithoutObjectCannotOpenReadiness(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(); unlink($f->blobs->path($f->ref)); $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            self::assertSame(Fixture::id(300),$f->blobs->catalog->db->execute("SELECT resource_id FROM reconciliation_findings WHERE outcome='missing_object'")->fetchColumn());
            self::assertSame(1,(int)$f->blobs->catalog->db->execute("SELECT count(*) FROM reconciliation_findings WHERE stage='objects' AND outcome='inventory_incomplete'")->fetchColumn());
            Fixture::expected('METADATA-WITHOUT-OBJECT',['serving'=>$f->serving(),'receipt'=>'missing_object']);
        });
    }
    public function testUnrecognizedObjectNamesAndUnknownSourcesRemainDurableObligations(): void
    {
        Fixture::run(function(Fixture $f): void {
            foreach ([Fixture::id(9),'unknown-source'] as $source) {
                $path=$f->blobs->root.'/'.$f->tenant->value.'/'.$source.'/loose.bin';
                mkdir(dirname($path),0700,true); file_put_contents($path,'unregistered'); chmod($path,0600);
            }
            $p=$f->plan(); $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            $c=$f->blobs->catalog; self::assertSame(2,$c->count('reconciliation_findings'));
            self::assertSame(1,$c->count('resource_ledger')); self::assertSame('blocked',$f->serving());
            self::assertSame(1,(int)$c->db->execute('SELECT count(*) FROM reconciliation_findings WHERE source_id IS NULL')->fetchColumn());
        });
    }
    public function testStagedWriterRemainsUnknownWithoutQuiescenceProof(): void
    {
        Fixture::run(function(Fixture $f): void {
            $reservation=$f->blobs->ledger->reserveWriter($f->blobs->reservation(700,701));
            $path=$f->blobs->root.'/'.$f->blobs->ledger->stagingKey($reservation);
            if (!is_dir(dirname($path))) { mkdir(dirname($path),0700,true); }
            file_put_contents($path,'staged'); chmod($path,0600);
            $p=$f->plan(); $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            $c=$f->blobs->catalog;
            self::assertSame('active',$c->db->execute('SELECT state FROM resource_writers WHERE id=?',[Fixture::id(701)])->fetchColumn());
            self::assertSame('reserved',$c->db->execute('SELECT state FROM resource_ledger WHERE id=?',[Fixture::id(700)])->fetchColumn());
            self::assertSame('unknown',$c->db->execute('SELECT outcome FROM reconciliation_findings')->fetchColumn()); self::assertFileExists($path);
        });
    }
    public function testUnknownExternalReceiptStaysUnknownAndBlocksReadiness(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->blobs->catalog->resource(800,2,1,'external'); $f->external=static fn()=>'unknown';
            $p=$f->plan(); $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            self::assertSame('unknown',$f->blobs->catalog->db->execute('SELECT outcome FROM reconciliation_findings')->fetchColumn());
            self::assertSame(Fixture::id(800),$f->blobs->catalog->db->execute('SELECT resource_id FROM reconciliation_findings')->fetchColumn());
            Fixture::expected('UNKNOWN-EXTERNAL-RECEIPT',['serving'=>$f->serving(),'external'=>'unknown','deletionReceipts'=>$f->blobs->catalog->count('deletion_attempts')]);
        });
    }
    public function testIncompleteJournalAndWrongDigestFailClosed(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(cutoff:new D\Decimal('1')); $r=$f->runner(); $r->start($p);
            Fixture::failure(fn()=>$r->step($p),'recovery_journal_gap');
            $m=$f->mark(); $f->marks=[$m->with('sequence','2')];
            Fixture::failure(fn()=>$r->step($p),'recovery_journal_gap');
            $f->marks=[$m]; $r->step($p);
            Fixture::failure(fn()=>$r->step($p),'recovery_journal_digest');
            Fixture::expected('INCOMPLETE-JOURNAL',['serving'=>$f->serving(),'proof'=>'unknown']);
        });
    }
    public function testIncompleteBackupAndCanaryFailureCannotProduceReadyReceipt(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(); $inventories=$p->inventories;
            $inventories['ledger']=new JsonObject(['count'=>2,'digest'=>$inventories['ledger']->get('digest')]);
            $bad=new D\RecoveryPlan($p->tenantId,$p->id,$p->backupSequence,$p->cutoff,$p->backupJournalDigest,$p->journalDigest,$inventories);
            $f->runner()->start($bad); self::assertSame('blocked',$f->finish($bad)['stage']);
            Fixture::expected('INCOMPLETE-BACKUP',['serving'=>$f->serving(),'readyReceipts'=>$f->blobs->catalog->count('restore_receipts')]);
            $f->canary=static fn()=>false; $fresh=$f->plan(90001); $f->runner()->start($fresh);
            Fixture::failure(fn()=>$f->finish($fresh),'recovery_canary_failed'); self::assertSame('blocked',$f->serving());
            Fixture::expected('DENIED-CANARY',['serving'=>$f->serving(),'readyReceipts'=>$f->blobs->catalog->count('restore_receipts')]);
            $fresh=$f->plan(90002); $inventories=$fresh->inventories;
            $inventories['outbox']=$inventories['outbox']->with('count',$inventories['outbox']->get('count')+1);
            $badOutbox=new D\RecoveryPlan($fresh->tenantId,$fresh->id,$fresh->backupSequence,$fresh->cutoff,$fresh->backupJournalDigest,$fresh->journalDigest,$inventories);
            $f->canary=null; $f->runner()->start($badOutbox); self::assertSame('blocked',$f->finish($badOutbox)['stage']);
            self::assertSame(1,(int)$f->blobs->catalog->db->execute("SELECT count(*) FROM reconciliation_findings WHERE run_id=? AND stage='outbox' AND outcome='inventory_incomplete'",[$badOutbox->id->value])->fetchColumn());
            self::assertSame('blocked',$f->serving());
        });
    }
    public function testInventoryDriftKeepsBaselineAndVerifiesRemainingStagesBeforeBlocking(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(); $fingerprint=$p->fingerprint(); unlink($f->blobs->path($f->ref));
            $key=$f->tenant->value.'/'.Fixture::id(2).'/sha256/'.hash('sha256','orphan').'/'.Fixture::id(700);
            $path=$f->blobs->root.'/'.$key; mkdir(dirname($path),0700,true); file_put_contents($path,'orphan'); chmod($path,0600);
            $canaries=0;
            $f->canary=function() use($f,$p,&$canaries): bool { self::assertSame('canary',$f->store->checkpoint($p)['stage']); ++$canaries; return true; };
            $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']); self::assertSame(1,$canaries);
            $c=$f->blobs->catalog;
            self::assertSame($fingerprint,$p->fingerprint()); self::assertSame(2,$c->count('resource_ledger'));
            self::assertSame($p->inventories['ledger']->get('digest'),Fixture::inventory($f->store->scan($p,'ledger',null,100))->get('digest'));
            $orphan=$c->db->row('SELECT id,state FROM resource_ledger WHERE storage_key=?',[$key]); self::assertSame('unknown',$orphan['state']);
            self::assertSame($orphan['id'],$c->db->execute("SELECT resource_id FROM reconciliation_findings WHERE outcome='unknown_object'")->fetchColumn());
            self::assertSame(Fixture::id(300),$c->db->execute("SELECT resource_id FROM reconciliation_findings WHERE outcome='missing_object'")->fetchColumn());
            self::assertSame(1,(int)$c->db->execute("SELECT count(*) FROM reconciliation_findings WHERE stage='objects' AND outcome='inventory_incomplete'")->fetchColumn());
            self::assertSame(0,$c->count('restore_receipts'));
            Fixture::expected('OBJECT-INVENTORY-DRIFT',['serving'=>$f->serving(),'remainingStages'=>'verified','baselineLedger'=>'unchanged']);
        });
    }
    public function testFindingsRetainExistingResourceAndGenerationIdentities(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c=$f->blobs->catalog; $c->db->execute("UPDATE resource_ledger SET state='unknown' WHERE id=?",[Fixture::id(300)]);
            $c->db->execute("INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,'postgres-fts-simple-v1','building')",[$f->tenant->value,Fixture::id(501),Fixture::id(100)]);
            $f->rebuild=static fn()=>false; // Explicit unknown backend result, never a successful qualification double.
            $p=$f->plan(); $f->runner()->start($p); self::assertSame('blocked',$f->finish($p)['stage']);
            $findings=$c->db->execute('SELECT stage,resource_id FROM reconciliation_findings ORDER BY stage')->fetchAll(\PDO::FETCH_ASSOC);
            self::assertSame([['stage'=>'ledger','resource_id'=>Fixture::id(300)],['stage'=>'manifests','resource_id'=>Fixture::id(501)],['stage'=>'objects','resource_id'=>Fixture::id(300)]],$findings);
            Fixture::expected('FINDING-IDENTITY',['serving'=>$f->serving(),'resourceId'=>'retained','generationId'=>'retained']);
        });
    }
    public function testTenantBindingIdempotentHeaderAndCheckpointCasAndWriterFreeze(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(); $r=$f->runner(); $r->start($p); $before=$f->store->checkpoint($p); $r->start($p); self::assertSame($before,$f->store->checkpoint($p));
            try { $r->step($p,101); self::fail('Unbounded recovery page accepted'); }
            catch (\InvalidArgumentException) { self::assertSame($before,$f->store->checkpoint($p)); }
            $wrong=new D\RecoveryPlan(new D\TenantId(Fixture::id(99)),$p->id,$p->backupSequence,$p->cutoff,$p->backupJournalDigest,$p->journalDigest,$p->inventories);
            Fixture::failure(fn()=>$r->start($wrong),'scope_mismatch',D\Outcome::Denied);
            CatalogFixture::sqlReject($f->blobs->catalog->db->pdo,'UPDATE resource_ledger SET ref_count=ref_count+1');
            CatalogFixture::sqlReject($f->blobs->catalog->db->pdo,"UPDATE tenants SET serving_state='ready'");
            $r->step($p); Fixture::failure(fn()=>$f->store->advance($p,$before,$before,[],[]),'recovery_checkpoint_conflict',D\Outcome::Incompatible);
            Fixture::expected('CHECKPOINT-SCOPE',['serving'=>$f->serving(),'staleCheckpoint'=>'rejected','writer'=>'frozen']);
        });
    }
}
