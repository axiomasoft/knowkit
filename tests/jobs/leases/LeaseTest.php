<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Application\Jobs\CancelJob;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('jobs:leases')]
final class LeaseTest extends TestCase
{
    public function testTwoAcquireAndStaleFenceAfterTakeover(): void
    {
        Fixture::run(function($f) {
            $id=$f->enqueue(); self::assertEquals($id,$f->enqueue());
            $a=$f->lease(); self::assertSame('1',$a->fence->value);
            self::assertNull($f->queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(901))));
            $m=$f->manifest($a); self::assertTrue($f->queue->checkpoint($a,$m->checkpoint()));
            $f->expire($a); self::assertFalse($f->queue->heartbeat($a));
            $b=$f->lease(); self::assertSame('2',$b->fence->value); self::assertNotEquals($a->attemptId,$b->attemptId);
            self::assertNotEquals($a->tokenHash(),$b->tokenHash());
            self::assertFalse($f->queue->complete($b,$f->result())); // retry cannot bypass revalidation
            self::assertFalse($f->queue->checkpoint($a,$m->checkpoint())); self::assertFalse($f->queue->complete($a,$f->result()));
            self::assertEquals($m->checkpoint(),$f->queue->resume($b,$f->processing));
            self::assertTrue($f->queue->complete($b,$f->result())); self::assertTrue($f->queue->complete($b,$f->result()));
            self::assertFalse($f->queue->complete($a,$f->result())); self::assertFalse($f->queue->heartbeat($a));
            self::assertSame('succeeded',$f->row()['state']); self::assertSame(1,$f->catalog->count('job_checkpoints'));
            self::assertFalse($f->queue->complete($b,new D\JobResult(D\Outcome::Success,new D\Sha256(str_repeat('f',64)))));
            $saved=Jcs::canonicalize(new JsonObject($f->row())); self::assertStringNotContainsString('"token"',$saved);
        });
    }
    public function testHeartbeatUsesDatabaseClockTokenAttemptEpochAndDeadline(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $lease=$f->lease(); $old=$f->row()['lease_until'];
            $f->catalog->db->execute("UPDATE jobs SET lease_until=clock_timestamp()+interval '1 second',attempt_deadline=clock_timestamp()+interval '3 seconds'");
            self::assertTrue($f->queue->heartbeat($lease)); self::assertSame($f->row()['attempt_deadline'],$f->row()['lease_until']);
            $wrong=new D\Lease($lease->scope,$lease->jobId,$lease->attemptId,$lease->sourceRef,$lease->generationId,$lease->fence,str_repeat('z',64),$lease->leaseUntil,$lease->attemptDeadline,$lease->sourceEpoch);
            self::assertFalse($f->queue->heartbeat($wrong)); self::assertFalse($f->queue->complete($wrong,$f->result()));
            $wrongAttempt=new D\Lease($lease->scope,$lease->jobId,new D\AttemptId(Fixture::id(999)),$lease->sourceRef,$lease->generationId,$lease->fence,str_repeat('z',64),$lease->leaseUntil,$lease->attemptDeadline,$lease->sourceEpoch);
            self::assertFalse($f->queue->heartbeat($wrongAttempt));
            $f->catalog->db->execute("UPDATE jobs SET attempt_deadline=clock_timestamp()-interval '1 second',lease_until=clock_timestamp()-interval '2 seconds'");
            self::assertFalse($f->queue->heartbeat($lease)); self::assertFalse($f->queue->checkpoint($lease,$f->manifest($lease)->checkpoint()));
            self::assertNotSame($old,$f->row()['lease_until']);
        });
    }
    public function testStagesCheckpointIntegrityAndAllProcessingAxes(): void
    {
        Fixture::run(function($f) {
            $f->enqueue('pipeline','ingest'); $a=$f->lease('ingest'); self::assertFalse($f->queue->complete($a,$f->result()));
            $wrongStage=$f->manifest($a,310,false,['stage'=>'index']); self::assertFalse($f->queue->checkpoint($a,$wrongStage->checkpoint()));
            $wrongInput=$f->manifest($a,310,false,['inputDigest'=>str_repeat('e',64)]); self::assertFalse($f->queue->checkpoint($a,$wrongInput->checkpoint()));
            $m=$f->manifest($a); self::assertTrue($f->queue->checkpoint($a,$m->checkpoint()));
            self::assertTrue($f->queue->checkpoint($a,$m->checkpoint())); self::assertSame('chunk',$f->row()['stage']);
            $f->expire($a); $b=$f->lease('ingest');
            $base=$f->processing->toWire();
            foreach (['sourceVersionId'=>Fixture::id(4),'sourceDigest'=>str_repeat('a',64),
                'parserSignature'=>$base->get('parserSignature')->with('version','changed'),
                'ocrProfileSignature'=>$base->get('ocrProfileSignature')->with('version','changed'),
                'chunkerSignature'=>$base->get('chunkerSignature')->with('version','changed'),
                'indexProfileDigest'=>str_repeat('a',64),'embeddingSpaceDigest'=>str_repeat('b',64)] as $key=>$value) {
                try { $f->queue->resume($b,PortFixture::processing($base->with($key,$value))); self::fail('Processing drift accepted: '.$key); }
                catch (D\DomainFailure $e) { self::assertSame('processing_identity_mismatch',$e->errorCode); }
            }
            try { PortFixture::processing($base->with('irSchema','ir-other')); self::fail('IR drift accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('unsupported_schema_version',$e->errorCode); }
            file_put_contents($f->directory.'/resource-'.Fixture::id(310),'tampered');
            try { $f->queue->resume($b,$f->processing); self::fail('Corrupt output resumed'); }
            catch (D\DomainFailure $e) { self::assertSame('checkpoint_digest_mismatch',$e->errorCode); }
            self::assertFalse($f->queue->checkpoint($b,$f->manifest($b,311)->checkpoint()));
            file_put_contents($f->directory.'/resource-'.Fixture::id(310),'artifact-310');
            self::assertEquals($m->checkpoint(),$f->queue->resume($b,$f->processing));
            $f->finishStages($b); self::assertTrue($f->queue->complete($b,$f->result())); self::assertSame(3,$f->row()['completed_stage_count']);
        });
    }
    public function testRetryBackoffAttemptsAndExplicitProviderUncertainty(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$f->lease(); $providerCalls=0;
            // Contract-only fault: provider may accept before its response is lost. No adapter is claimed.
            $provider=function() use(&$providerCalls): void { $providerCalls++; throw new D\DomainFailure(D\Outcome::Unknown,'provider_timeout'); };
            try { $provider(); } catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unknown,$e->outcome); }
            $uncertain=$f->manifest($a,310,true); self::assertTrue($f->queue->checkpoint($a,$uncertain->checkpoint()));
            self::assertSame(0,$f->row()['completed_stage_count']); self::assertNull($f->row()['checkpoint_digest']);
            self::assertTrue($f->queue->complete($a,$f->result(D\Outcome::Unknown)));
            self::assertTrue($f->row()['uncertain_external_effect']); self::assertSame('unknown',$f->row()['accepted_outcome']);
            self::assertNull($f->queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(900))));
            self::assertSame([5,10,20,40,80],array_map(PdoJobQueue::backoff(...),range(1,5)));
            for ($attempt=2;$attempt<=5;$attempt++) {
                $f->catalog->db->execute("UPDATE jobs SET available_at=clock_timestamp()-interval '1 second'");
                $lease=$f->lease(); self::assertSame($attempt,$f->row()['attempt_count']); self::assertNull($f->queue->resume($lease,$f->processing));
                try { $provider(); } catch (D\DomainFailure) {}
                self::assertTrue($f->queue->complete($lease,$f->result(D\Outcome::Unavailable)));
                $delta=(float)$f->catalog->db->row('SELECT extract(epoch FROM available_at-clock_timestamp()) AS seconds FROM jobs')['seconds'];
                self::assertGreaterThan(PdoJobQueue::backoff($attempt)-2,$delta);
            }
            self::assertSame(5,$providerCalls); self::assertSame('failed',$f->row()['state']); self::assertTrue($f->row()['uncertain_external_effect']);
            self::assertNull($f->queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(901))));
        });
    }
    public function testCancelInvalidatesExtractionBeforeSupervisorStopAndKeepsUncertainWriters(): void
    {
        Fixture::run(function($f) {
            $id=$f->enqueue(); $a=$f->lease(); $m=$f->manifest($a);
            $f->catalog->db->execute("INSERT INTO resource_writers VALUES (?,?,?,?, 'active',?,clock_timestamp(),NULL)",
                [Fixture::id(1),Fixture::id(700),Fixture::id(310),$a->fence->value,$id->value]);
            $stop=new CancelJob($f->queue,function($job,$grace) use($f,$a,$m): void {
                self::assertFalse($f->catalog->db->pdo->inTransaction()); self::assertSame(5,$grace);
                self::assertSame('cancelled',$f->row()['state']); self::assertFalse($f->queue->checkpoint($a,$m->checkpoint()));
            });
            self::assertTrue($stop->cancel($f->scope,$id)); self::assertSame('2',(string)$f->row()['fence']);
            self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            self::assertFalse($f->queue->complete($a,$f->result())); self::assertFalse($f->queue->heartbeat($a));
            self::assertTrue($f->queue->cancel($f->scope,$id)); self::assertSame('2',(string)$f->row()['fence']);
        });
    }
    public function testLifecycleEpochTenantAndImmutableCheckpointGuards(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$f->lease(); $m=$f->manifest($a);
            $foreign=new D\Scope(new D\TenantId(Fixture::id(9)),$f->scope->kbId,$f->scope->actorId,$f->scope->purpose,$f->scope->policyEpoch,$f->scope->hostScopeHash);
            try { $f->queue->cancel($foreign,$a->jobId); self::fail('Cross tenant accepted'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied,$e->outcome); }
            $foreignManifest=$f->manifest($a,310,false,['sourceVersionId'=>Fixture::id(4)]);
            self::assertFalse($f->queue->checkpoint($a,$foreignManifest->checkpoint()));
            self::assertTrue($f->queue->checkpoint($a,$m->checkpoint()));
            CatalogFixture::sqlReject($f->catalog->db->pdo,"UPDATE job_checkpoints SET output_digest=repeat('a',64)");
            CatalogFixture::sqlReject($f->catalog->db->pdo,"UPDATE jobs SET processing_digest=repeat('a',64)");
            $f->catalog->db->execute('UPDATE sources SET lifecycle_epoch=lifecycle_epoch+1');
            self::assertFalse($f->queue->heartbeat($a)); self::assertFalse($f->queue->complete($a,$f->result()));
            $f->expire($a); self::assertNull($f->queue->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(901))));
        });
        foreach (['corrected','revoked','tombstone','blocked'] as $mode) {
            Fixture::run(function($f) use($mode) {
                $f->enqueue(); $a=$f->lease();
                match($mode) {
                    'corrected'=>$f->catalog->db->execute("UPDATE source_version_state SET usability='corrected'"),
                    'revoked'=>$f->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked'"),
                    'blocked'=>$f->catalog->db->execute("UPDATE tenants SET serving_state='blocked'"),
                    'tombstone'=>$f->catalog->db->execute("INSERT INTO tombstones VALUES (?,?,?,'source_revoked',?,1,'future_versions')",[Fixture::id(1),Fixture::id(800),Fixture::id(2),Fixture::id(801)])
                };
                self::assertFalse($f->queue->heartbeat($a)); self::assertFalse($f->queue->complete($a,$f->result()));
            });
        }
    }
    public function testMigrationIdentityBoundsEnqueueConflictAndFenceExhaustion(): void
    {
        Fixture::run(function($f) {
            self::assertSame(2,$f->catalog->count('schema_migrations'));
            $f->enqueue();
            try { $f->enqueue('create-kb'); self::fail('Cross-operation idempotency key accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('idempotency_conflict',$e->errorCode); }
            try { $f->queue->enqueue($f->command('ingest'),new D\IdempotencyKey('job')); self::fail('Different request replay accepted'); }
            catch (D\DomainFailure $e) { self::assertSame('idempotency_conflict',$e->errorCode); }
            CatalogFixture::sqlReject($f->catalog->db->pdo,'UPDATE jobs SET completed_stage_count=17');
            CatalogFixture::sqlReject($f->catalog->db->pdo,"UPDATE jobs SET stage_plan_json='[]'::jsonb");
            $f->catalog->db->execute('UPDATE jobs SET fence=9223372036854775807');
            try { $f->lease(); self::fail('Fence wrapped'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unavailable,$e->outcome); self::assertSame('decimal_exhausted',$e->errorCode); }
            self::assertSame('9223372036854775807',(string)$f->row()['fence']); self::assertSame('pending',$f->row()['state']);
        });
    }
    public function testUncertainProviderRetrySucceedsOnceLocallyWithoutClearingUncertainty(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$f->lease(); $calls=1; // contract double accepted before timeout
            $f->resource(310,'remote-accepted');
            $f->catalog->db->execute("INSERT INTO resource_writers VALUES (?,?,?,?, 'active',?,clock_timestamp(),NULL)",
                [Fixture::id(1),Fixture::id(700),Fixture::id(310),$a->fence->value,$a->jobId->value]);
            self::assertTrue($f->queue->checkpoint($a,$f->manifest($a,310,true)->checkpoint()));
            self::assertTrue($f->queue->complete($a,$f->result(D\Outcome::Unknown)));
            $f->catalog->db->execute("UPDATE jobs SET available_at=clock_timestamp()-interval '1 second'");
            $b=$f->lease(); self::assertNull($f->queue->resume($b,$f->processing)); $calls++;
            self::assertTrue($f->queue->checkpoint($b,$f->manifest($b,311)->checkpoint())); $effects=0;
            $effect=static function() use(&$effects): void { $effects++; };
            self::assertTrue($f->queue->accept($b,$f->result(),$effect));
            self::assertTrue($f->queue->accept($b,$f->result(),$effect));
            self::assertSame(2,$calls); self::assertSame(1,$effects); self::assertTrue($f->row()['uncertain_external_effect']);
            self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            self::assertFalse($f->queue->accept($a,$f->result(),$effect)); self::assertSame(1,$effects);
        });
    }
    public function testHostScopeResourceOwnershipAndFinalResourceStateAreAuthoritative(): void
    {
        Fixture::run(function($f) {
            $f->enqueue();
            $scope=new D\Scope($f->scope->tenantId,$f->scope->kbId,$f->scope->actorId,$f->scope->purpose,$f->scope->policyEpoch,new D\Sha256(str_repeat('f',64)));
            $partition=Fixture::makeQueue($f->catalog->db,$scope,$f->directory);
            self::assertNull($partition->lease(new D\JobType('extract'),new D\WorkerId(Fixture::id(900))));
            $a=$f->lease(); $f->catalog->source(5); $ref=$f->resource(320,'foreign-source',5);
            $m=$f->manifest($a,310,false,['resources'=>[$ref],'outputDigest'=>\KnowKit\Domain\Contract\HashDomain::ResourceInventory->digest([$ref])]);
            self::assertFalse($f->queue->checkpoint($a,$m->checkpoint()));
            $f->finishStages($a);
            $f->catalog->db->execute("INSERT INTO resource_writers VALUES (?,?,?,?, 'uncertain',?,clock_timestamp(),NULL)",
                [Fixture::id(1),Fixture::id(700),Fixture::id(310),$a->fence->value,$a->jobId->value]);
            self::assertFalse($f->queue->complete($a,$f->result()));
            self::assertSame('leased',$f->row()['state']);
            CatalogFixture::sqlReject($f->catalog->db->pdo,"UPDATE resource_ledger SET state='deleting' WHERE id='".Fixture::id(310)."'");
        });
    }
    public function testLostCommitResponseIsUnknownAndReplaysDurableAcceptance(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$f->lease(); $f->finishStages($a);
            $pdo=new class(getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',getenv('TEST_PG_USER')?:'knowkit_test',getenv('TEST_PG_PASSWORD')?:null) extends \PDO {
                public function commit(): bool { parent::commit(); throw new \PDOException('Simulated lost commit response'); }
            };
            $role=$f->catalog->db->pdo->query('SELECT current_user')->fetchColumn();
            \KnowKit\Adapter\Postgres\Catalog\Connection::schema($role);
            $pdo->exec('SET ROLE '.$role); $pdo->exec('SET search_path TO '.$f->catalog->db->schema);
            $db=new \KnowKit\Adapter\Postgres\Catalog\Connection($pdo,$f->catalog->db->schema);
            $queue=Fixture::makeQueue($db,$f->scope,$f->directory);
            try { $queue->complete($a,$f->result()); self::fail('Unconfirmed commit reported success'); }
            catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Unknown,$e->outcome); self::assertSame('job_commit_unknown',$e->errorCode); }
            self::assertSame('succeeded',$f->row()['state']); self::assertTrue($f->queue->complete($a,$f->result()));
        });
    }
    public function testRelinquishingUnavailableAttemptRetainsOpenWriterUncertainty(): void
    {
        Fixture::run(function($f) {
            $f->enqueue(); $a=$f->lease(); $f->resource(310,'accepted-before-timeout');
            $f->catalog->db->execute("INSERT INTO resource_writers VALUES (?,?,?,?, 'active',?,clock_timestamp(),NULL)",
                [Fixture::id(1),Fixture::id(700),Fixture::id(310),$a->fence->value,$a->jobId->value]);
            self::assertTrue($f->queue->complete($a,$f->result(D\Outcome::Unavailable)));
            self::assertTrue($f->row()['uncertain_external_effect']); self::assertSame('pending',$f->row()['state']);
            self::assertSame('uncertain',$f->catalog->db->row('SELECT state FROM resource_writers')['state']);
            CatalogFixture::sqlReject($f->catalog->db->pdo,'UPDATE jobs SET uncertain_external_effect=false');
            self::assertTrue($f->queue->cancel($f->scope,$a->jobId)); self::assertTrue($f->row()['uncertain_external_effect']);
        });
    }
}
