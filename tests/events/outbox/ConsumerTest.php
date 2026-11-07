<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Application\Events\Consumer;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,JsonObject};
use KnowKit\Port\EventAuthority;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('events:outbox')]
final class ConsumerTest extends TestCase
{
    public function testDuplicateAndReorderedEpochsNeverReviveSourceOrGeneration(): void
    {
        Fixture::run(function(Fixture $f): void {
            $inbox = $f->inbox(); $consumer = $f->consumer($inbox);
            $new = Fixture::event(503,'3'); self::assertSame('gap',$consumer->consume($new->canonical()));
            self::assertSame('gap',$consumer->consume($new->canonical()));
            $registered = Fixture::event(500,'0',new JsonObject(['sourceId'=>Fixture::id(2),'lifecycleState'=>'active','lifecycleEpoch'=>'0','sourcePolicyEpoch'=>'0','retentionHold'=>false]),'source.registered');
            self::assertSame('gap',$consumer->consume($registered->canonical()));
            $row = $inbox->projection('source',new D\Ulid(Fixture::id(2)));
            self::assertSame('3',$row->get('aggregateVersion')); self::assertSame('3',$row->get('lifecycleEpoch'));
            self::assertTrue($row->get('stale')); self::assertTrue($row->get('invalidated')); self::assertTrue($row->get('versionGap'));
            self::assertSame(2,$f->catalog->count('inbox')); self::assertSame(1,$f->catalog->count('event_projections'));
            self::assertSame(0,$f->catalog->count('sources')); // consumer cannot register or reopen authoritative identities
            $base = Fixture::event(510,'1')->toWire();
            $payload = new JsonObject(['kbId'=>Fixture::id(100),'generationId'=>Fixture::id(501),'manifestDigest'=>str_repeat('a',64)]);
            $sealed = new D\DomainEvent($base->with('aggregateType','generation')->with('aggregateId',Fixture::id(501))->with('type','generation.sealed')
                ->with('payload',$payload)->with('payloadDigest',\KnowKit\Domain\Contract\HashDomain::DomainEventPayload->digest($payload)),PortFixture::validator());
            self::assertSame('applied',$consumer->consume($sealed->canonical()));
            self::assertTrue($inbox->projection('generation',new D\Ulid(Fixture::id(501)))->get('invalidated'));
            self::assertSame(0,$f->catalog->count('index_generations')); self::assertSame(0,$f->catalog->count('publication_snapshots'));
            Fixture::outcome('OUT-OF-ORDER',['epoch'=>'3','invalidated'=>true,'reactivated'=>false]);
            Fixture::outcome('DUPLICATE',['effects'=>1,'repeatedEffect'=>false]);
        });
    }
    public function testEventIdConflictDurablyBlocksConsumerAcrossRestart(): void
    {
        Fixture::run(function(Fixture $f): void {
            $consumer = $f->consumer($f->inbox()); self::assertSame('applied',$consumer->consume(Fixture::event()->canonical()));
            $conflict = Fixture::event(500,'1',new JsonObject(['sourceId'=>Fixture::id(2),'reason'=>'consent_revoked','lifecycleEpoch'=>'1','appliesTo'=>'future_versions']));
            self::assertSame('blocked',$consumer->consume($conflict->canonical()));
            self::assertSame('blocked',$f->consumer($f->inbox('invalidate',$f->connection()))->consume(Fixture::event(501,'2')->canonical()));
            self::assertSame(1,$f->catalog->count('inbox')); self::assertSame('event_identity_conflict',$f->catalog->db->execute('SELECT error_code FROM event_consumers')->fetchColumn());
            // A different consumer has an independent inbox and remains operational.
            self::assertSame('applied',$f->consumer($f->inbox('other'))->consume(Fixture::event()->canonical()));
            Fixture::outcome('EVENT-ID-CONFLICT',['blocked'=>true,'inboxCount'=>1]);
        });
    }
    public function testSameSequenceDifferentPayloadBlocksInsteadOfOverwriting(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c = $f->consumer($f->inbox()); self::assertSame('applied',$c->consume(Fixture::event()->canonical()));
            $conflict = Fixture::event(501,'1',new JsonObject(['sourceId'=>Fixture::id(2),'reason'=>'source_deleted','lifecycleEpoch'=>'1','appliesTo'=>'future_versions']));
            self::assertSame('blocked',$c->consume($conflict->canonical()));
            self::assertSame('aggregate_sequence_conflict',$f->catalog->db->execute('SELECT error_code FROM event_consumers')->fetchColumn());
            self::assertSame(1,$f->catalog->count('inbox')); self::assertSame(1,$f->catalog->count('event_sequences'));
            Fixture::outcome('SEQUENCE-CONFLICT',['blocked'=>true,'overwritten'=>false]);
        });
    }
    public function testPoisonUnknownTypeVersionDigestAndSensitivePayloadQuarantine(): void
    {
        Fixture::run(function(Fixture $f): void {
            $c = $f->consumer($f->inbox()); $w = Fixture::event()->toWire();
            $bad = [$w->with('schemaVersion','event-2.0'),$w->with('type','unknown.event'),$w->with('payloadDigest',str_repeat('0',64)),
                $w->with('payload',$w->get('payload')->with('text','secret document text'))];
            foreach ($bad as $poison) { self::assertSame('quarantined',$c->consume(Jcs::canonicalize($poison))); }
            self::assertSame('quarantined',$c->consume('{secret raw provider input'));
            self::assertSame('quarantined',$c->consume('{secret raw provider input'));
            self::assertSame(5,$f->catalog->count('event_quarantine')); self::assertSame(0,$f->catalog->count('inbox'));
            self::assertStringNotContainsString('secret',json_encode($f->catalog->db->execute('SELECT * FROM event_quarantine')->fetchAll(),JSON_THROW_ON_ERROR));
            self::assertSame('applied',$c->consume(Fixture::event()->canonical()));
            Fixture::outcome('POISON-CONSUMER',['quarantined'=>5,'rawInputsStored'=>false,'healthyApplied'=>true]);
        });
    }
    public function testInboxAndProjectionRollbackTogetherOnFailure(): void
    {
        Fixture::run(function(Fixture $f): void {
            $pdo = $f->catalog->db->pdo; $role = $pdo->query('SELECT current_user')->fetchColumn(); $pdo->exec('RESET ROLE');
            try {
                $pdo->exec("CREATE FUNCTION reject_inbox() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test injection' USING ERRCODE='23514'; END \$\$");
                $pdo->exec('CREATE TRIGGER injected BEFORE INSERT ON inbox FOR EACH ROW EXECUTE FUNCTION reject_inbox()');
            } finally { $pdo->exec('SET ROLE '.$role); }
            $c = $f->consumer($f->inbox()); Fixture::failure(fn() => $c->consume(Fixture::event()->canonical()),'storage_conflict');
            self::assertSame(0,$f->catalog->count('event_projections')); self::assertSame(0,$f->catalog->count('inbox')); self::assertSame(0,$f->catalog->count('event_sequences'));
            $pdo->exec('RESET ROLE'); try { $pdo->exec('DROP TRIGGER injected ON inbox'); } finally { $pdo->exec('SET ROLE '.$role); }
            self::assertSame('applied',$c->consume(Fixture::event()->canonical()));
            Fixture::outcome('ATOMIC-INBOX',['rollbackEffects'=>0,'rollbackInbox'=>0,'retryEffects'=>1]);
        });
    }
    public function testGapLoadsCommittedAuthorityAndUnavailableRemainsStale(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->catalog->source(); $f->catalog->db->execute('UPDATE sources SET lifecycle_epoch=3');
            $inbox = $f->inbox(); $consumer = $f->consumer($inbox);
            self::assertSame('gap',$consumer->consume(Fixture::event(503,'3')->canonical()));
            self::assertFalse($inbox->projection('source',new D\Ulid(Fixture::id(2)))->get('versionGap'));
            self::assertTrue($inbox->projection('source',new D\Ulid(Fixture::id(2)))->get('stale'));
            self::assertSame('duplicate',$consumer->consume(Fixture::event(503,'3')->canonical()));
            $unknown = new class implements EventAuthority { public function version(D\DomainEvent $e): ?D\Decimal { throw new D\DomainFailure(D\Outcome::Unavailable,'authority_unavailable'); } };
            $consumer = new Consumer(new D\TenantId(Fixture::id(1)),PortFixture::validator(),$inbox,$unknown);
            self::assertSame('gap',$consumer->consume(Fixture::event(505,'5')->canonical()));
            $inbox->reconcile(Fixture::event(503,'3'),new D\Decimal('3'));
            self::assertTrue($inbox->projection('source',new D\Ulid(Fixture::id(2)))->get('versionGap'));
            self::assertSame('gap',$consumer->consume(Fixture::event(505,'5')->canonical()));
            Fixture::outcome('GAP-AUTHORITY',['authorityLoaded'=>true,'unavailableStale'=>true,'olderReadClearsGap'=>false]);
        });
    }
    public function testTenantIdentityCheckedAtConsumerAndPersistenceBoundaries(): void
    {
        Fixture::run(function(Fixture $f): void {
            $f->catalog->tenant(9); $inbox = $f->inbox(); $e = Fixture::event(500,'1',null,'source.revoked',9);
            Fixture::failure(fn() => $f->consumer($inbox)->consume($e->canonical()),'scope_mismatch',D\Outcome::Denied);
            Fixture::failure(fn() => $inbox->apply($e),'scope_mismatch',D\Outcome::Denied);
            Fixture::failure(fn() => $inbox->reconcile($e,new D\Decimal('1')),'scope_mismatch',D\Outcome::Denied);
            self::assertSame(0,$f->catalog->count('inbox')); self::assertSame(0,$f->catalog->count('event_projections'));
            self::assertSame('applied',$f->inbox('invalidate',null,9)->apply($e));
            self::assertNull($inbox->projection('source',new D\Ulid(Fixture::id(2))));
            Fixture::outcome('TENANT-ISOLATION',['crossTenant'=>'denied','foreignProjectionVisible'=>false]);
        });
    }
    public function testLargeDecimalPolicyAndPointerEpochsNeverDecreaseAndHoldCannotGrantAccess(): void
    {
        Fixture::run(function(Fixture $f): void {
            $inbox = $f->inbox(); $c = $f->consumer($inbox); $large = '9007199254740993';
            $registered = Fixture::event(600,$large,new JsonObject(['sourceId'=>Fixture::id(2),'lifecycleState'=>'active',
                'lifecycleEpoch'=>$large,'sourcePolicyEpoch'=>$large,'retentionHold'=>false]),'source.registered');
            $c->consume($registered->canonical());
            $c->consume(Fixture::event(601,'2',new JsonObject(['sourceId'=>Fixture::id(2),'retentionHold'=>false]),'retention_hold.changed')->canonical());
            $source = $inbox->projection('source',new D\Ulid(Fixture::id(2)));
            self::assertSame($large,$source->get('lifecycleEpoch')); self::assertSame($large,$source->get('sourcePolicyEpoch'));
            self::assertTrue($source->get('invalidated')); self::assertTrue($source->get('stale'));
            foreach ([[$large,602],['2',603]] as [$version,$id]) {
                $w = Fixture::event($id,$version)->toWire();
                $p = new JsonObject(['kbId'=>Fixture::id(100),'snapshotId'=>Fixture::id($id+100),'manifestDigest'=>str_repeat('a',64),'pointerVersion'=>$version]);
                $e = new D\DomainEvent($w->with('aggregateType','knowledge_base')->with('aggregateId',Fixture::id(100))->with('type','snapshot.activated')
                    ->with('payload',$p)->with('payloadDigest',\KnowKit\Domain\Contract\HashDomain::DomainEventPayload->digest($p)),PortFixture::validator());
                $c->consume($e->canonical());
            }
            self::assertSame($large,$inbox->projection('knowledge_base',new D\Ulid(Fixture::id(100)))->get('pointerVersion'));
            self::assertSame(0,$f->catalog->count('publication_snapshots')); self::assertSame(0,$f->catalog->count('knowledge_bases'));
        });
    }
}
