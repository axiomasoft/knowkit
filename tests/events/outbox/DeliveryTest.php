<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Application\Events\Dispatcher;
use KnowKit\Domain as D;
use KnowKit\Port\EventTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('events:outbox')]
final class DeliveryTest extends TestCase
{
    public function testLeaseCommitFencesTokensExpiryAndTenantScope(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->append(Fixture::event()); $d = $f->delivery(); $other = $f->delivery($f->connection());
            $lease = $d->leaseDelivery(1)[0]; self::assertFalse($f->catalog->db->pdo->inTransaction());
            self::assertSame([],$other->leaseDelivery(1)); self::assertSame(Fixture::event()->canonical(),$d->event($lease)->canonical());
            $forged = new D\DeliveryLease($lease->tenantId,$lease->eventId,$lease->fence,str_repeat('wrong',8),$lease->expiresAt);
            self::assertFalse($d->ack($forged)); self::assertFalse($d->retry($forged,1)); self::assertFalse($d->quarantine($forged));
            self::assertStringNotContainsString(str_repeat('wrong',8),print_r($forged,true));
            $f->catalog->tenant(9); Fixture::failure(fn() => $f->delivery(null,9)->ack($lease),'scope_mismatch',D\Outcome::Denied);
            $f->catalog->db->execute("UPDATE outbox SET lease_until=clock_timestamp()-interval '1 second' WHERE tenant_id=?",[Fixture::id(1)]);
            self::assertFalse($d->ack($lease)); self::assertFalse($d->quarantine($lease));
            $next = $other->leaseDelivery(1)[0]; self::assertSame('2',$next->fence->value);
            self::assertFalse($d->ack($lease)); self::assertFalse($d->retry($lease,1)); self::assertTrue($other->ack($next)); self::assertFalse($other->ack($next));
            self::assertSame('2',(string)$f->catalog->db->execute('SELECT attempts FROM outbox')->fetchColumn());
            Fixture::outcome('LEASE-FENCE',['staleAck'=>false,'forgedAck'=>false,'attempts'=>'2','delivered'=>true]);
        });
    }
    public function testSkipLockedAndBoundedBatches(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->append(Fixture::event(500)); $f->append(Fixture::event(501,'2'));
            $db = $f->catalog->db; $db->pdo->beginTransaction();
            try {
                $db->execute('SELECT id FROM outbox WHERE tenant_id=? AND id=? FOR UPDATE',[Fixture::id(1),Fixture::id(500)]);
                $leases = $f->delivery($f->connection())->leaseDelivery(64);
                self::assertCount(1,$leases); self::assertSame(Fixture::id(501),$leases[0]->eventId->value);
            } finally { $db->pdo->rollBack(); }
            foreach ([0,65] as $limit) {
                try { $f->delivery()->leaseDelivery($limit); self::fail('Unbounded batch accepted'); }
                catch (\InvalidArgumentException $error) { self::assertSame('Batch must be between 1 and 64',$error->getMessage()); }
            }
            Fixture::outcome('SKIP-LOCKED',['lockedRowSkipped'=>true,'batchBound'=>64]);
        });
    }
    public function testTimeoutAfterConsumerCommitRetriesWithoutRepeatingEffect(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->append(Fixture::event()); $inbox = $f->inbox(); $consumer = $f->consumer($inbox);
            $transport = new class($f,$consumer) implements EventTransport {
                public int $calls = 0;
                public array $results = [];
                public function __construct(private Fixture $f, private \KnowKit\Application\Events\Consumer $consumer) {}
                public function send(D\DomainEvent $event): void
                {
                    TestCase::assertFalse($this->f->catalog->db->pdo->inTransaction());
                    $this->results[] = $this->consumer->consume($event->canonical());
                    if (++$this->calls === 1) { throw new \RuntimeException('secret provider input must never persist'); }
                }
            };
            $d = $f->delivery(); $dispatcher = new Dispatcher($d,$transport);
            self::assertSame(1,$dispatcher->dispatch()->get('retried')); self::assertSame('pending',$f->catalog->db->execute('SELECT delivery_state FROM outbox')->fetchColumn());
            self::assertSame(0,$dispatcher->dispatch()->get('delivered')); // retry delay is honored
            $f->catalog->db->execute("UPDATE outbox SET available_at=clock_timestamp()-interval '1 second'");
            self::assertSame(1,$dispatcher->dispatch()->get('delivered')); self::assertSame(['applied','duplicate'],$transport->results);
            self::assertSame(1,$f->catalog->count('inbox')); self::assertSame(1,$f->catalog->count('event_sequences'));
            $status = $d->status(); self::assertSame('1',$status->get('transportErrors')); self::assertSame(0,$status->get('outstanding'));
            self::assertStringNotContainsString('secret',json_encode($f->catalog->db->execute('SELECT * FROM outbox')->fetchAll(),JSON_THROW_ON_ERROR));
            Fixture::outcome('TIMEOUT-AFTER-APPLY',['effects'=>1,'deliveries'=>2,'transportErrors'=>'1']);
        });
    }
    public function testPoisonOutboxIsQuarantinedAndDoesNotStarveHealthyEvent(): void
    {
        Fixture::run(function(Fixture $f): void {
            $w = Fixture::event()->toWire()->with('schemaVersion','event-9.0');
            $f->catalog->db->execute('INSERT INTO outbox(tenant_id,id,event_json,payload_digest,aggregate_version) VALUES (?,?,?::jsonb,?,?)',
                [Fixture::id(1),$w->get('eventId'),\KnowKit\Domain\Contract\Jcs::canonicalize($w),$w->get('payloadDigest'),'1']);
            $f->append(Fixture::event(501,'2'));
            $transport = new class implements EventTransport { public int $calls=0; public function send(D\DomainEvent $event): void { ++$this->calls; } };
            $d = $f->delivery(); $report = (new Dispatcher($d,$transport))->dispatch();
            self::assertSame(1,$report->get('quarantined')); self::assertSame(1,$report->get('delivered')); self::assertSame(1,$transport->calls);
            self::assertSame(1,$d->status()->get('quarantined')); self::assertSame('0',$d->status()->get('transportErrors'));
            Fixture::outcome('POISON-DISPATCH',['quarantined'=>1,'healthyDelivered'=>1]);
        });
    }
    public function testLagTransportErrorsAndImmutableEventIdentity(): void
    {
        Fixture::run(function(Fixture $f): void {
            $e = Fixture::event(); $w = $e->toWire();
            $f->catalog->db->execute("INSERT INTO outbox(tenant_id,id,event_json,payload_digest,aggregate_version,enqueued_at)
                VALUES (?,?,?::jsonb,?,?,clock_timestamp()-interval '90 seconds')",[Fixture::id(1),$w->get('eventId'),$e->canonical(),$w->get('payloadDigest'),'1']);
            $d = $f->delivery();
            self::assertSame(1,$d->status()->get('outstanding')); self::assertGreaterThanOrEqual(90,(int)$d->status()->get('oldestOutstandingSeconds'));
            $before = $f->catalog->db->execute('SELECT event_json FROM outbox')->fetchColumn();
            CatalogSql::reject($f,"UPDATE outbox SET event_json=jsonb_set(event_json,'{schemaVersion}','\"event-9.0\"')");
            CatalogSql::reject($f,'DELETE FROM outbox');
            self::assertSame($before,$f->catalog->db->execute('SELECT event_json FROM outbox')->fetchColumn());
            $l = $d->leaseDelivery(1)[0]; self::assertTrue($d->retry($l,1));
            self::assertSame('transport_error',$f->catalog->db->execute('SELECT last_error_code FROM outbox')->fetchColumn());
            Fixture::outcome('OPERATIONS',['lagVisible'=>true,'transportErrors'=>'1','immutable'=>true]);
        });
    }
}

final class CatalogSql
{
    public static function reject(Fixture $f, string $sql): void { \KnowKit\Tests\Storage\Catalog\CatalogFixture::sqlReject($f->catalog->db->pdo,$sql,[],['23514']); }
}
