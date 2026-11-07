<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Application\Events\Dispatcher;
use KnowKit\Domain as D;
use KnowKit\Port\EventTransport;
use KnowKit\Tests\Publication\Activate\Fixture as Publication;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__,2).'/publication/activate/Fixture.php';

#[Group('events:outbox')]
final class DurabilityTest extends TestCase
{
    public function testKillBeforeAndAfterPublicationCommitKeepsOutboxAtomic(): void
    {
        foreach (['before','after'] as $phase) {
            Publication::run(function(Publication $p) use ($phase): void {
                $r = $p->request(); $p->seal($r); $f = new Fixture($p->manifest->jobs->catalog);
                $before = $f->catalog->count('outbox');
                $actor = new Worker($p->db(),'publication-'.$phase,$p->manifest->jobs->directory,$r);
                try { self::assertSame($phase === 'before' ? 'PRECOMMIT' : 'COMMITTED',$actor->line()); $actor->kill(); }
                finally { $actor->close(); }
                self::assertSame($phase === 'before' ? 0 : 1,$f->catalog->count('publication_snapshots'));
                self::assertSame($before + ($phase === 'after' ? 1 : 0),$f->catalog->count('outbox'));
                if ($phase === 'before') { self::assertNull($p->manifest->pointer()); }
                else {
                    $row = $p->db()->row("SELECT event_json,delivery_state FROM outbox WHERE tenant_id=? AND event_json->>'type'='snapshot.activated'",[Fixture::id(1)]);
                    self::assertSame('pending',$row['delivery_state']); self::assertSame($p->manifest->pointer(),json_decode($row['event_json'],true)['payload']['snapshotId']);
                }
                $receipt = $p->services($r)->publisher->publish($r); // rollback retries, commit replays the first receipt
                self::assertSame($receipt->toWire()->get('snapshotId'),$p->manifest->pointer());
                $transport = new class implements EventTransport {
                    public array $types = [];
                    public function send(D\DomainEvent $e): void { $this->types[] = $e->toWire()->get('type'); }
                };
                (new Dispatcher($f->delivery($f->connection()),$transport))->dispatch();
                self::assertContains('snapshot.activated',$transport->types);
                self::assertSame(0,$f->delivery()->status()->get('outstanding'));
            });
        }
        Fixture::outcome('KILL-BEFORE-COMMIT',['publicationVisible'=>false,'eventVisible'=>false]);
        Fixture::outcome('KILL-AFTER-COMMIT',['publicationVisible'=>true,'eventDispatchable'=>true]);
        Fixture::outcome('R11-A02',['rollbackExposesNeither'=>true,'committedSnapshotConsistent'=>true,'durablyDispatchable'=>true]);
    }
    public function testKillLifecycleMutationAndEventShareTheCommit(): void
    {
        foreach (['before','after'] as $phase) {
            Publication::run(function(Publication $p) use ($phase): void {
                $r = $p->request(); $f = new Fixture($p->manifest->jobs->catalog); $before = $f->catalog->count('outbox');
                $actor = new Worker($p->db(),'lifecycle-'.$phase,$p->manifest->jobs->directory,$r);
                try { self::assertSame($phase === 'before' ? 'PRECOMMIT' : 'COMMITTED',$actor->line()); $actor->kill(); }
                finally { $actor->close(); }
                self::assertSame($phase === 'before' ? 'active' : 'revoked',$p->db()->execute('SELECT lifecycle_state FROM sources')->fetchColumn());
                self::assertSame($phase === 'before' ? 0 : 1,$f->catalog->count('tombstones'));
                self::assertSame($before + ($phase === 'after' ? 1 : 0),$f->catalog->count('outbox'));
                $p->services($r)->lifecycle($r->lease->scope)->revoke($r->lease->scope,new D\Revocation($r->lease->sourceRef->sourceId,new D\Decimal('0')),new D\IdempotencyKey('crash-revoke'));
                self::assertSame('revoked',$p->db()->execute('SELECT lifecycle_state FROM sources')->fetchColumn());
                self::assertSame($before+1,$f->catalog->count('outbox'));
            });
        }
        Fixture::outcome('LIFECYCLE-ATOMIC',['rollbackExposesNeither'=>true,'replayEpoch'=>'1','eventCount'=>1]);
    }
    public function testInterruptedUploadedStagingAndProgressCannotPublish(): void
    {
        Publication::run(function(Publication $p): void {
            $r = $p->request(); $p->services($r)->staging->stage($r->lease,$r->candidate);
            $db = $p->db(); $role = $db->execute('SELECT current_user')->fetchColumn();
            $resources = $p->manifest->jobs->catalog->count('resource_ledger'); self::assertGreaterThan(0,$resources);
            $actor = proc_open([PHP_BINARY,dirname(__DIR__,2).'/publication/manifest/interrupted.php',$db->schema,$role],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,Fixture::root(),getenv());
            self::assertIsResource($actor);
            try { stream_set_timeout($pipes[1],10); self::assertSame("PARTIAL\n",fgets($pipes[1])); self::assertTrue(proc_terminate($actor,9)); }
            finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($actor); }
            self::assertSame('building',$p->manifest->state()); self::assertNull($p->manifest->pointer());
            self::assertSame($resources,$p->manifest->jobs->catalog->count('resource_ledger'));
            self::assertSame($r->candidate->digest->value,$db->execute('SELECT manifest_digest FROM generation_checks')->fetchColumn());
            Fixture::failure(fn() => $p->services($r)->publisher->publish($r),'index_unreadable',D\Outcome::Unavailable);
            // A transport progress notification has no event schema and no authority to publish.
            $f = new Fixture($p->manifest->jobs->catalog);
            self::assertSame('quarantined',$f->consumer($f->inbox())->consume('{"progress":100}'));
            self::assertNull($p->manifest->pointer());
            $p->seal($r); $p->services($r)->publisher->publish($r); self::assertNotNull($p->manifest->pointer());
        });
        Fixture::outcome('R11-A01',['partialActive'=>false,'stagedArtifactsIdentifiable'=>true,'retryPublished'=>true]);
        Fixture::outcome('PROGRESS-NOT-PUBLICATION',['progress100Publishes'=>false]);
    }
    public function testDeliveryLeaseSurvivesDispatcherKill(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->append(Fixture::event()); $actor = new Worker($f->catalog->db,'delivery-before-send');
            try { self::assertSame('LEASED',$actor->line()); $actor->kill(); } finally { $actor->close(); }
            self::assertSame('leased',$f->catalog->db->execute('SELECT delivery_state FROM outbox')->fetchColumn());
            self::assertSame([],$f->delivery()->leaseDelivery(1));
            $f->catalog->db->execute("UPDATE outbox SET lease_until=clock_timestamp()-interval '1 second'");
            $lease = $f->delivery()->leaseDelivery(1)[0]; self::assertSame('2',$lease->fence->value); self::assertTrue($f->delivery()->ack($lease));
            Fixture::outcome('KILL-LEASE',['redelivered'=>true,'fence'=>'2']);
        });
    }
}
