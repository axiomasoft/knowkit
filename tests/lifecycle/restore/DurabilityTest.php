<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:restore')]
final class DurabilityTest extends TestCase
{
    public function testSigkillBetweenVerificationAndCheckpointReplaysTheSameBoundedPage(): void
    {
        Fixture::run(function(Fixture $f): void {
            $p=$f->plan(); $r=$f->runner(); $r->start($p);
            while ($f->store->checkpoint($p)['stage']!=='ledger') { $r->step($p,1); }
            $before=$f->store->checkpoint($p); $c=$f->blobs->catalog; $pipes=[];
            $process=proc_open([PHP_BINARY,__DIR__.'/worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process);
            try {
                fwrite($pipes[0],json_encode(['schema'=>$c->sandbox->schema,'role'=>$c->db->execute('SELECT current_user')->fetchColumn(),
                    'root'=>$f->blobs->root,'plan'=>json_decode(Jcs::canonicalize($p->wire()),true)],JSON_THROW_ON_ERROR)."\n"); fflush($pipes[0]);
                stream_set_timeout($pipes[1],10); $line=fgets($pipes[1]);
                self::assertSame("VERIFIED\n",$line,'Worker must finish real local digest verification before SIGKILL');
                self::assertTrue(proc_terminate($process,9));
                $deadline=microtime(true)+5;
                do { $status=proc_get_status($process); if (!$status['running']) { break; } usleep(10000); } while (microtime(true)<$deadline);
                self::assertFalse($status['running']); self::assertTrue($status['signaled']); self::assertSame(9,$status['termsig']);
                self::assertSame($before,$f->store->checkpoint($p)); self::assertSame('blocked',$f->serving());
                self::assertSame('ready',$f->finish($p)['stage']);
                self::assertSame('abc',BlobFixture::read($f->blobs->store->open($f->ref)));
                Fixture::expected('KILL-RECONCILIATION',['signal'=>$status['termsig'],'checkpointBeforeKill'=>$before['count'],'servingAfterRestart'=>$f->serving()]);
            } finally {
                $status=proc_get_status($process); if ($status['running']) { proc_terminate($process,9); }
                foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process);
            }
        });
    }
    public function testLifecycleAndVersionHighWaterMarksSurviveBeforeTheirMetadata(): void
    {
        Fixture::run(function(Fixture $f): void {
            $mark=$f->mark()->with('lifecycleState','active')->with('lifecycleEpoch','7')->with('sourcePolicyEpoch','8')->with('tombstone',null)
                ->with('versions',[new JsonObject(['sourceVersionId'=>Fixture::id(3),'usability'=>'corrected','eventId'=>Fixture::id(600),'correctedByVersionId'=>null]),
                    new JsonObject(['sourceVersionId'=>Fixture::id(4),'usability'=>'corrected','eventId'=>Fixture::id(601),'correctedByVersionId'=>null])]);
            $f->marks=[$mark]; $p=$f->plan(); $f->runner()->start($p); self::assertSame('ready',$f->finish($p)['stage']);
            $c=$f->blobs->catalog;
            self::assertSame('7',(string)$c->db->execute('SELECT lifecycle_epoch FROM sources')->fetchColumn());
            self::assertSame('8',(string)$c->db->execute('SELECT source_policy_epoch FROM sources')->fetchColumn());
            CatalogFixture::sqlReject($c->db->pdo,'UPDATE sources SET lifecycle_epoch=6');
            $c->db->execute("INSERT INTO source_versions VALUES (?,?,?,?,?,?,'text/plain','test:local',?,clock_timestamp())",[$f->tenant->value,Fixture::id(3),Fixture::id(2),Fixture::id(300),$f->ref->digest->value,'3',hash('sha256','test-version')]);
            CatalogFixture::sqlReject($c->db->pdo,"INSERT INTO source_version_state VALUES (?,?,'usable',NULL,NULL)",[$f->tenant->value,Fixture::id(3)]);
            $c->source(9); $c->resource(900,9);
            CatalogFixture::sqlReject($c->db->pdo,"INSERT INTO source_versions VALUES (?,?,?,?,?,?,'text/plain','test:local',?,clock_timestamp())",
                [$f->tenant->value,Fixture::id(4),Fixture::id(9),Fixture::id(900),hash('sha256','synthetic bytes'),'15',hash('sha256','wrong-source')]);
            Fixture::expected('LIFECYCLE-HIGH-WATER',['sourceEpoch'=>'7','sourcePolicyEpoch'=>'8','lateVersion'=>'rejected']);
            $f->marks=[$mark->with('sourceId',Fixture::id(9))]; $retry=$f->plan(90001); $f->runner()->start($retry);
            Fixture::failure(fn()=>$f->runner()->step($retry,1),'recovery_version_source_mismatch');
            self::assertSame('blocked',$f->serving());
        });
    }
    public function testRequiredBackendFailurePreservesUnavailableAndDoesNotAdvance(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->blobs->catalog->resource(800,2,1,'external');
            $f->external=static fn()=>throw new D\DomainFailure(D\Outcome::Unavailable,'required_backend_down');
            $p=$f->plan(); $r=$f->runner(); $r->start($p);
            while ($f->store->checkpoint($p)['stage']!=='ledger') { $r->step($p,1); }
            $r->step($p,1); $before=$f->store->checkpoint($p);
            Fixture::failure(fn()=>$r->step($p,1),'required_backend_down',D\Outcome::Unavailable);
            self::assertSame($before,$f->store->checkpoint($p));
            Fixture::expected('REQUIRED-BACKEND',['serving'=>$f->serving(),'outcome'=>'unavailable']);
        });
    }
    public function testExistingVersionReplayRetainsTheAuthoritativeEventWithoutInventingMetadata(): void
    {
        foreach (['corrected','revoked'] as $usability) {
            Fixture::run(function(Fixture $f) use($usability): void {
                self::version($f,3); $c=$f->blobs->catalog;
                $mark=$f->mark();
                if ($usability==='corrected') { $mark=$mark->with('lifecycleState','active')->with('tombstone',null); }
                $f->marks=[$mark->with('versions',[new JsonObject(['sourceVersionId'=>Fixture::id(3),'usability'=>$usability,
                    'eventId'=>Fixture::id(600),'correctedByVersionId'=>null])])];
                $p=$f->plan(); $f->runner()->start($p); self::assertSame('ready',$f->finish($p)['stage']);
                $state=$c->db->row('SELECT * FROM source_version_state WHERE id=?',[Fixture::id(3)]);
                self::assertSame($usability,$state['usability']); self::assertNull($state['event_id']);
                self::assertSame(0,$c->count('lifecycle_events'));
                $logical=self::versionState($f);
                self::assertSame($usability,$logical->usability->value); self::assertSame(Fixture::id(600),$logical->eventId->value);
                CatalogFixture::sqlReject($c->db->pdo,"UPDATE source_version_state SET usability='usable' WHERE id=?",[Fixture::id(3)]);
                if ($usability==='corrected') {
                    Fixture::expected('EXISTING-VERSION-REPLAY',['serving'=>$f->serving(),'usability'=>$state['usability'],
                        'eventMetadata'=>'absent','versionRows'=>$c->count('source_versions')]);
                }
            });
        }
    }
    public function testPostBackupReplacementIsRetainedWithoutCreatingAFakeVersionOrEvent(): void
    {
        Fixture::run(function(Fixture $f): void {
            self::version($f,3); $c=$f->blobs->catalog;
            $mark=$f->mark()->with('lifecycleState','active')->with('tombstone',null)->with('versions',[
                new JsonObject(['sourceVersionId'=>Fixture::id(3),'usability'=>'corrected','eventId'=>Fixture::id(600),'correctedByVersionId'=>Fixture::id(4)])]);
            $f->marks=[$mark]; $p=$f->plan(); $f->runner()->start($p); self::assertSame('ready',$f->finish($p)['stage']);
            $projected=$c->db->row('SELECT * FROM source_version_state WHERE id=?',[Fixture::id(3)]);
            self::assertSame('corrected',$projected['usability']); self::assertNull($projected['event_id']); self::assertNull($projected['corrected_by_version_id']);
            $saved=$c->db->row('SELECT event_id,corrected_by_version_id FROM recovery_version_marks');
            self::assertSame(Fixture::id(600),$saved['event_id']); self::assertSame(Fixture::id(4),$saved['corrected_by_version_id']);
            self::assertSame(1,$c->count('source_versions')); self::assertSame(0,$c->count('lifecycle_events'));
            $logical=self::versionState($f);
            self::assertSame(Fixture::id(600),$logical->eventId->value); self::assertSame(Fixture::id(4),$logical->correctedByVersionId->value);
            Fixture::expected('POST-BACKUP-REPLACEMENT',['serving'=>$f->serving(),'replacement'=>'preserved','replacementMetadata'=>'absent','eventMetadata'=>'absent']);
        });
    }
    public function testAvailableEventAndReplacementRemainExactAndCannotBeCleared(): void
    {
        Fixture::run(function(Fixture $f): void {
            self::version($f,3); self::version($f,4); $c=$f->blobs->catalog;
            $c->db->execute("INSERT INTO lifecycle_events VALUES (?,?,?,?,'source_version.corrected',1,?)",
                [$f->tenant->value,Fixture::id(600),Fixture::id(2),Fixture::id(3),hash('sha256','original-event-payload')]);
            $event=$c->db->row('SELECT * FROM lifecycle_events');
            $f->marks=[$f->mark()->with('lifecycleState','active')->with('tombstone',null)->with('versions',[
                new JsonObject(['sourceVersionId'=>Fixture::id(3),'usability'=>'corrected','eventId'=>Fixture::id(600),'correctedByVersionId'=>Fixture::id(4)])])];
            $p=$f->plan(); $f->runner()->start($p); self::assertSame('ready',$f->finish($p)['stage']);
            self::assertSame($event,$c->db->row('SELECT * FROM lifecycle_events'));
            $state=$c->db->row('SELECT * FROM source_version_state WHERE id=?',[Fixture::id(3)]);
            self::assertSame(Fixture::id(600),$state['event_id']); self::assertSame(Fixture::id(4),$state['corrected_by_version_id']);
            CatalogFixture::sqlReject($c->db->pdo,'UPDATE source_version_state SET event_id=NULL WHERE id=?',[Fixture::id(3)]);
            CatalogFixture::sqlReject($c->db->pdo,'UPDATE source_version_state SET corrected_by_version_id=NULL WHERE id=?',[Fixture::id(3)]);
        });
    }
    public function testInvalidRecoveryReplacementMarksAreRejectedInReplayAndStorage(): void
    {
        foreach ([['corrected',3],['revoked',4]] as [$usability,$replacement]) {
            Fixture::run(function(Fixture $f) use($usability,$replacement): void {
                self::version($f,3); $c=$f->blobs->catalog;
                CatalogFixture::sqlReject($c->db->pdo,'INSERT INTO recovery_version_marks VALUES (?,?,?,?,?,?)',
                    [$f->tenant->value,Fixture::id(2),Fixture::id(3),$usability,Fixture::id(600),Fixture::id($replacement)]);
                $f->marks=[$f->mark()->with('lifecycleState','active')->with('tombstone',null)->with('versions',[
                    new JsonObject(['sourceVersionId'=>Fixture::id(3),'usability'=>$usability,'eventId'=>Fixture::id(600),'correctedByVersionId'=>Fixture::id($replacement)])])];
                $p=$f->plan(); $f->runner()->start($p); $before=$f->store->checkpoint($p);
                Fixture::failure(fn()=>$f->runner()->step($p,1),'recovery_version_mark_invalid',D\Outcome::Incompatible);
                self::assertSame($before,$f->store->checkpoint($p)); self::assertSame(0,$c->count('recovery_version_marks'));
                self::assertSame('usable',$c->db->execute('SELECT usability FROM source_version_state')->fetchColumn());
                Fixture::expected('RECOVERY-MARK-INVARIANTS',['selfReplacement'=>'rejected','revokedReplacement'=>'rejected','checkpoint'=>'unchanged']);
            });
        }
    }
    private static function version(Fixture $f, int $version): void
    {
        $c=$f->blobs->catalog;
        $c->db->execute("INSERT INTO source_versions VALUES (?,?,?,?,?,?,'text/plain','test:local',?,clock_timestamp())",
            [$f->tenant->value,Fixture::id($version),Fixture::id(2),Fixture::id(300),$f->ref->digest->value,'3',hash('sha256','version-'.$version)]);
        $c->db->execute("INSERT INTO source_version_state VALUES (?,?,'usable',NULL,NULL)",[$f->tenant->value,Fixture::id($version)]);
    }
    private static function versionState(Fixture $f): D\SourceVersionState
    {
        $c=$f->blobs->catalog;
        return $c->tx->run(function() use($f,$c): D\SourceVersionState {
            $c->tx->lockTenant($f->tenant,D\LockMode::Share);
            $store=new PdoLifecycleStore($c->db,$c->tx,$f->tenant,$c->clock,$c->ids,PortFixture::validator(),$c->events->create(...));
            return $store->getVersionState($c->scope(),new D\SourceVersionId(Fixture::id(3))) ?? throw new \RuntimeException('Existing version state required');
        });
    }
}
