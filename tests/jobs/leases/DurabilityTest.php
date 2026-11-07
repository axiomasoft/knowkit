<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';
require_once __DIR__.'/PublicationFixture.php';

/** Adapted AgenTools DurableTest process barriers; no donor test-pass claim. */
#[Group('jobs:leases')]
final class DurabilityTest extends TestCase
{
    private function worker(Fixture $f,string $mode): array
    {
        $role=$f->catalog->db->pdo->query('SELECT current_user')->fetchColumn(); $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$f->catalog->db->schema,$role,$f->directory,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3));
        self::assertIsResource($process); stream_set_timeout($pipes[1],10); $worker=[$process,$pipes];
        self::assertSame('READY',$this->line($worker)); return $worker;
    }
    private function line(array $worker): string
    { $line=fgets($worker[1][1]); self::assertNotFalse($line,'Worker barrier missing'); return trim($line); }
    private function go(array $worker,?D\Lease $lease=null): void
    { fwrite($worker[1][0],($lease===null?'GO':base64_encode(serialize($lease)))."\n"); fflush($worker[1][0]); }
    private function finish(array $worker,bool $kill=false): void
    {
        if ($kill) { proc_terminate($worker[0],9); }
        fclose($worker[1][0]); $err=stream_get_contents($worker[1][2]); fclose($worker[1][2]); fclose($worker[1][1]);
        $exit=proc_close($worker[0]); self::assertSame('',$err); if (!$kill) { self::assertSame(0,$exit); }
    }
    private function decodeLease(string $line): ?D\Lease
    { if ($line==='NONE') { return null; } self::assertStringStartsWith('LEASE ',$line); $value=unserialize(base64_decode(substr($line,6),true)); self::assertInstanceOf(D\Lease::class,$value); return $value; }
    public function testCompetingProcessesAcquireOnlyOneCapability(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$this->worker($f,'acquire'); $b=$this->worker($f,'acquire');
            try {
                $this->go($a); $this->go($b); $left=$this->decodeLease($this->line($a)); $right=$this->decodeLease($this->line($b));
                self::assertNotSame($left===null,$right===null); self::assertSame(1,$f->row()['attempt_count']);
            } finally { $this->finish($a); $this->finish($b); }
        });
    }
    public function testCompetingRecoveryAfterLostProcessStateRejectsOldTransitions(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $old=$f->lease(); $checkpoint=$f->manifest($old); self::assertTrue($f->queue->checkpoint($old,$checkpoint->checkpoint()));
            $f->expire($old); $a=$this->worker($f,'acquire'); $b=$this->worker($f,'acquire');
            try {
                $this->go($a); $this->go($b); $left=$this->decodeLease($this->line($a)); $right=$this->decodeLease($this->line($b));
                self::assertNotSame($left===null,$right===null); $winner=$left??$right; self::assertSame('2',$winner->fence->value);
            } finally { $this->finish($a); $this->finish($b); }
            $fresh=Fixture::makeQueue($f->connection(),$f->scope,$f->directory);
            self::assertFalse($fresh->checkpoint($old,$checkpoint->checkpoint())); self::assertFalse($fresh->complete($old,$f->result()));
            self::assertFalse($fresh->complete($winner,$f->result()));
            self::assertEquals($checkpoint->checkpoint(),$fresh->resume($winner,$f->processing)); self::assertTrue($fresh->complete($winner,$f->result()));
        });
    }
    public function testConcurrentEqualContentAttemptsAndStaleSharedTarget(): void
    {
        Fixture::run(function($f) {
            $generation=PublicationFixture::prepare($f); $f->enqueue('first','extract',$generation); $f->enqueue('second','extract',$generation);
            $a=$this->worker($f,'acquire'); $b=$this->worker($f,'acquire');
            try {
                $this->go($a); $this->go($b); $left=$this->decodeLease($this->line($a)); $right=$this->decodeLease($this->line($b));
                self::assertNotNull($left); self::assertNotNull($right); self::assertNotEquals($left->jobId,$right->jobId);
                self::assertNotEquals($left->attemptId,$right->attemptId); self::assertEquals($left->sourceRef,$right->sourceRef);
            } finally { $this->finish($a); $this->finish($b); }
            $f->finishStages($left); $f->expire($left); $next=$f->lease(); self::assertEquals($left->jobId,$next->jobId);
            $called=false;
            self::assertFalse($f->queue->accept($left,$f->result(),function() use(&$called): void { $called=true; })); self::assertFalse($called);
            self::assertSame('building',$f->catalog->db->row('SELECT state FROM index_generations WHERE id=?',[Fixture::id(501)])['state']);
            self::assertSame(Fixture::id(600),$f->catalog->db->row('SELECT active_snapshot_id FROM knowledge_bases')['active_snapshot_id']);
            self::assertEquals(new D\Checkpoint(new D\Sha256($f->row($left->jobId)['checkpoint_digest']),false),$f->queue->resume($next,$f->processing));
        });
    }
    public function testSkipLockedAndTypedSemanticLockTimeout(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $worker=$this->worker($f,'hold-job');
            try {
                $this->go($worker); self::assertSame('LOCKED',$this->line($worker));
                self::assertNull($f->queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(900))));
            } finally { $this->go($worker); $this->finish($worker); }
            $other=$f->connection(); $other->pdo->beginTransaction();
            try {
                $other->row('SELECT id FROM '.$other->table('knowledge_bases').' WHERE tenant_id=? AND id=? FOR UPDATE',[Fixture::id(1),Fixture::id(100)]);
                $start=hrtime(true);
                try { $f->lease(); self::fail('Lock timeout became empty work'); }
                catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unavailable,$e->outcome); self::assertSame('RETRYABLE_LOCK_TIMEOUT',$e->errorCode); }
                self::assertLessThan(2,(hrtime(true)-$start)/1e9); self::assertFalse($f->catalog->db->pdo->inTransaction());
            } finally { $other->pdo->rollBack(); }
        });
    }
    public function testKillBeforeCheckpointRetainsStagedArtifactsWithoutPublication(): void
    {
        Fixture::run(function($f) {
            $generation=PublicationFixture::prepare($f); $f->enqueue('staging','extract',$generation); $lease=$f->lease();
            $worker=$this->worker($f,'staged-kill');
            try { $this->go($worker,$lease); self::assertSame('STAGED',$this->line($worker)); }
            finally { $this->finish($worker,true); }
            self::assertSame(0,$f->catalog->count('job_checkpoints'));
            self::assertSame(Fixture::id(600),$f->catalog->db->row('SELECT active_snapshot_id FROM knowledge_bases')['active_snapshot_id']);
            self::assertSame('building',$f->catalog->db->row('SELECT state FROM index_generations WHERE id=?',[Fixture::id(501)])['state']);
            $staged=$f->catalog->db->row('SELECT * FROM resource_ledger WHERE id=?',[Fixture::id(350)]);
            self::assertSame(Fixture::id(351),$staged['staging_id']); self::assertSame(hash('sha256','staged-output'),$staged['digest']);
            self::assertSame('staged-output',file_get_contents($f->directory.'/resource-'.Fixture::id(350)));
            $f->expire($lease); $next=$f->lease(); self::assertNull($f->queue->resume($next,$f->processing));
            self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            self::assertTrue($f->row()['uncertain_external_effect']); self::assertFalse($f->queue->complete($lease,$f->result()));
        });
    }
    public function testCancelWhileExtractionProcessIsRunningRejectsItsLateResult(): void
    {
        Fixture::run(function($f) {
            $job=$f->enqueue(); $lease=$f->lease(); $worker=$this->worker($f,'extracting');
            try {
                $this->go($worker,$lease); self::assertSame('EXTRACTING',$this->line($worker));
                self::assertTrue($f->queue->cancel($f->scope,$job)); $this->go($worker); self::assertSame('STALE',$this->line($worker));
            } finally { $this->finish($worker); }
            self::assertSame('cancelled',$f->row()['state']);
        });
    }
    public function testKillBeforeCommitRollsBackJobPointerSnapshotAndOutbox(): void
    {
        Fixture::run(function($f) {
            $gen=PublicationFixture::prepare($f); $f->enqueue('atomic','extract',$gen); $lease=$f->lease(); $f->finishStages($lease);
            $before=$f->catalog->count('outbox'); $worker=$this->worker($f,'complete-before');
            try { $this->go($worker,$lease); self::assertSame('PRECOMMIT',$this->line($worker)); }
            finally { $this->finish($worker,true); }
            self::assertSame('leased',$f->row()['state']); self::assertSame($before,$f->catalog->count('outbox'));
            self::assertSame(1,$f->catalog->count('publication_snapshots'));
            self::assertSame(Fixture::id(600),$f->catalog->db->row('SELECT active_snapshot_id FROM knowledge_bases')['active_snapshot_id']);
            $f->expire($lease); $fresh=Fixture::makeQueue($f->catalog->db,$f->scope,$f->directory); $next=$f->lease();
            self::assertNotNull($fresh->resume($next,$f->processing));
            self::assertFalse($fresh->accept($lease,$f->result(),static function(): void { self::fail('Stale SQL effect ran'); }));
            $tx=new PdoCatalogTransaction($f->catalog->db); $fresh=Fixture::makeQueue($f->catalog->db,$f->scope,$f->directory,$tx);
            self::assertTrue($fresh->accept($next,$f->result(),PublicationFixture::effect($f->catalog->db,$tx,$f->scope)));
            self::assertSame($before+1,$f->catalog->count('outbox'));
        });
    }
    public function testKillAfterCommitReplaysSameReceiptAndLeavesEventDispatchable(): void
    {
        Fixture::run(function($f) {
            $gen=PublicationFixture::prepare($f); $f->enqueue('atomic','extract',$gen); $lease=$f->lease(); $f->finishStages($lease);
            $before=$f->catalog->count('outbox'); $worker=$this->worker($f,'complete-after');
            try { $this->go($worker,$lease); self::assertSame('COMMITTED',$this->line($worker)); }
            finally { $this->finish($worker,true); }
            self::assertSame('succeeded',$f->row()['state']); self::assertSame($before+1,$f->catalog->count('outbox'));
            self::assertSame(Fixture::id(601),$f->catalog->db->row('SELECT active_snapshot_id FROM knowledge_bases')['active_snapshot_id']);
            self::assertTrue($f->catalog->db->row('SELECT sealed FROM publication_snapshots WHERE id=?',[Fixture::id(601)])['sealed']);
            self::assertSame('pending',$f->catalog->db->row('SELECT delivery_state FROM outbox WHERE id=?',[Fixture::id(800)])['delivery_state']);
            $connection=$f->connection(); $fresh=Fixture::makeQueue($connection,$f->scope,$f->directory);
            self::assertTrue($fresh->accept($lease,$f->result(),static function(): void { self::fail('Committed effect repeated'); }));
            self::assertSame($before+1,$f->catalog->count('outbox'));
        });
    }
}
