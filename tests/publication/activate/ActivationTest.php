<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Activate;

use KnowKit\Adapter\Lexical\{CanonicalChunkSets, PdoLexicalIndex};
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\TestIds;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('publication:activate')]
final class ActivationTest extends TestCase
{
    public function testPreviousPointerGenerationIsRetiredEvenWhenItStillHasSealedState(): void
    {
        Fixture::run(function(Fixture $f): void {
            $previous = $f->request(500); $f->seal($previous); $f->manifest->snapshot(500,600,true);
            self::assertSame('sealed',$f->manifest->state(500));
            $next = $f->request(); $f->seal($next); $f->services($next)->publisher->publish($next);
            self::assertSame('retired',$f->manifest->state(500)); self::assertSame('active',$f->manifest->state(501));
            $old = new D\SnapshotRef($next->lease->scope->tenantId,$next->lease->scope->kbId,new D\SnapshotId(Fixture::id(600)),new D\Fingerprint(hash('sha256','snapshot-600')));
            self::assertSame(Fixture::id(500),$f->resolve(D\SnapshotSelector::exact($old))->generationIds[0]->value);
            Fixture::outcome('GENERATION-RETIREMENT',['old'=>'retired','new'=>'active','history'=>'pinned']);
        });
    }

    public function testAtomicSnapshotAndReplayReturnTheFirstReceipt(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(); $f->seal($r); $service = $f->services($r);
            $receipt = $service->publisher->publish($r); $w = $receipt->toWire();
            self::assertSame($w->get('snapshotId'),$f->manifest->pointer());
            self::assertSame(Fixture::data()->get('firstSnapshotDigest'),$w->get('manifestDigest'));
            self::assertSame('active',$f->manifest->state());
            $db = $f->db(); $counts = $db->execute('SELECT count(*) FROM outbox')->fetchColumn(); $reads = $service->reads;
            // Metadata replay survives later lifecycle/pointer changes and unavailable object storage.
            $db->execute('UPDATE sources SET lifecycle_epoch=lifecycle_epoch+1,source_policy_epoch=source_policy_epoch+1 WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(2)]);
            unlink($f->manifest->jobs->directory.'/resource-'.Fixture::id(311));
            self::assertSame($receipt->canonical(),$service->publisher->publish($r)->canonical());
            self::assertSame($reads,$service->reads); self::assertSame($counts,$db->execute('SELECT count(*) FROM outbox')->fetchColumn());
            self::assertSame('1',(string)$db->execute('SELECT pointer_version FROM knowledge_bases')->fetchColumn());
            $changed = new D\ActivationRequest($r->lease,$r->candidate,new D\Decimal('1'),$r->key);
            Fixture::failure(fn() => $service->publisher->publish($changed),'idempotency_conflict');
            Fixture::failure(fn() => $service->publisher->publish($f->request(502)),'idempotency_conflict');
            self::assertSame(1,$f->manifest->jobs->catalog->count('publication_snapshots'));
            Fixture::outcome('REPLAY',['pointerVersion'=>$w->get('pointerVersion'),'sameReceipt'=>true,'duplicateEvents'=>false]);
        });
    }

    public function testOutboxRollbackAndInterruptedDispatchNeverSplitPublication(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(); $f->seal($r); $catalog = $f->manifest->jobs->catalog;
            $events = $catalog->count('outbox'); $receipts = $catalog->count('idempotency_receipts');
            $broken = new class($catalog->outbox) implements P\Outbox {
                public function __construct(private P\Outbox $inner) {}
                public function append(D\DomainEvent $event): void { $this->inner->append($event); throw new D\DomainFailure(D\Outcome::Unavailable,'dispatcher_interrupted'); }
            };
            Fixture::failure(fn() => $f->services($r,$broken)->publisher->publish($r),'dispatcher_interrupted',D\Outcome::Unavailable);
            self::assertNull($f->manifest->pointer()); self::assertSame(0,$catalog->count('publication_snapshots'));
            self::assertSame($events,$catalog->count('outbox')); self::assertSame($receipts,$catalog->count('idempotency_receipts'));
            self::assertSame('sealed',$f->manifest->state());
            $receipt = $f->services($r)->publisher->publish($r);
            // There is deliberately no dispatcher call in the publication transaction.
            $fresh = $f->manifest->jobs->connection();
            $event = $fresh->row('SELECT delivery_state,event_json FROM outbox WHERE tenant_id=? AND id=?',[Fixture::id(1),$receipt->toWire()->get('eventId')]);
            self::assertSame('pending',$event['delivery_state']);
            self::assertSame($receipt->toWire()->get('snapshotId'),$f->manifest->pointer());
            self::assertSame($events+1,$catalog->count('outbox'));
            Fixture::outcome('R11-A02',['rollbackSnapshotCount'=>0,'committedEvent'=>'pending','pointerConsistent'=>true]);
        });
    }

    public function testFreshRightsAndEpochFenceChecksRejectLateAcceptance(): void
    {
        $cases = [
            'lifecycle'=>["UPDATE sources SET lifecycle_epoch=1",'source_changed',D\Outcome::Denied],
            'source-policy'=>["UPDATE sources SET source_policy_epoch=1",'staging_conflict',D\Outcome::Incompatible],
            'kb-policy'=>["UPDATE knowledge_bases SET kb_policy_epoch=kb_policy_epoch+1",'policy_changed',D\Outcome::Denied],
            'fence'=>["UPDATE jobs SET fence=fence+1",'writer_fenced',D\Outcome::Incompatible],
            'expired'=>["UPDATE jobs SET lease_until=clock_timestamp()-interval '1 second'",'writer_fenced',D\Outcome::Incompatible],
            'cancelled'=>["UPDATE jobs SET cancel_requested=true",'writer_fenced',D\Outcome::Incompatible],
            'corrected'=>["UPDATE source_version_state SET usability='corrected'",'source_changed',D\Outcome::Denied],
            'blocked'=>["UPDATE tenants SET serving_state='blocked'",'tenant_blocked',D\Outcome::Unavailable],
            'membership'=>["DELETE FROM source_memberships",'source_changed',D\Outcome::Denied],
            'ancestor'=>["UPDATE dependency_nodes SET invalidated_epoch=1 WHERE kind='document_revision'",'source_unusable',D\Outcome::Denied],
        ];
        foreach ($cases as $name=>[$sql,$code,$outcome]) {
            Fixture::run(function(Fixture $f) use ($name,$sql,$code,$outcome): void {
                $r = $f->request(); $f->seal($r); $f->db()->execute($sql);
                Fixture::failure(fn() => $f->services($r)->publisher->publish($r),$code,$outcome);
                self::assertNull($f->manifest->pointer(),$name); self::assertSame(0,$f->manifest->jobs->catalog->count('publication_snapshots'));
            });
        }
        foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
            Fixture::run(function(Fixture $f) use ($decision): void {
                $r = $f->request(); $f->seal($r); $catalog = $f->manifest->jobs->catalog;
                $catalog->owner->decision = $decision;
                Fixture::failure(fn() => $f->services($r,null,$catalog->owner)->publisher->publish($r),
                    $decision === D\Decision::Deny ? 'policy_denied' : 'policy_unknown', $decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown);
                self::assertNull($f->manifest->pointer());
            });
        }
        Fixture::outcome('STALE-EPOCH',['accepted'=>false]);
        Fixture::outcome('R12-A02',['freshAuthorization'=>'denied','publicationCount'=>0]);
    }

    public function testPreparedOwnerReceiptCannotSurviveAnEpochChange(): void
    {
        foreach (['tenants'=>'tenant_policy_epoch','knowledge_bases'=>'kb_policy_epoch','sources'=>'source_policy_epoch'] as $table=>$column) {
            Fixture::run(function(Fixture $f) use ($table,$column): void {
                $r = $f->request(); $f->seal($r);
                $service = $f->services($r,null,null,fn() => $f->db()->execute("UPDATE $table SET $column=$column+1"));
                Fixture::failure(fn() => $service->publisher->publish($r),$table === 'knowledge_bases' ? 'policy_changed' : 'POLICY_CHANGED',D\Outcome::Denied);
                self::assertNull($f->manifest->pointer());
            });
        }
    }

    public function testMissingRequiredProjectionAndUnsealedGenerationCannotPublish(): void
    {
        foreach ([false,true] as $seal) {
            Fixture::run(function(Fixture $f) use ($seal): void {
                $r = $f->request();
                if ($seal) {
                    $f->seal($r);
                    // A privileged backend failure simulation: sealed logical membership survives.
                    $f->loseProjection();
                    $code = 'index_unreadable';
                } else {
                    $service = $f->services($r); $service->staging->stage($r->lease,$r->candidate); $f->manifest->index($r->candidate);
                    $code = 'generation_not_ready';
                }
                Fixture::failure(fn() => $f->services($r)->publisher->publish($r),$code,D\Outcome::Unavailable);
                self::assertNull($f->manifest->pointer()); self::assertSame(0,$f->manifest->jobs->catalog->count('publication_snapshots'));
            });
        }
        Fixture::outcome('MISSING-REQUIRED-PROJECTION',['accepted'=>false,'partialActive'=>false]);
    }

    public function testReadinessIsRecheckedInsideTheTransaction(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(); $f->seal($r);
            $service = $f->services($r,null,null,$f->loseProjection(...));
            Fixture::failure(fn() => $service->publisher->publish($r),'index_unreadable',D\Outcome::Unavailable);
            self::assertNull($f->manifest->pointer());
        });
    }

    public function testResolveLatestOnceAndPinnedHistoryKeepExactRefsAndCurrentRights(): void
    {
        Fixture::run(function(Fixture $f): void {
            $first = $f->request(); $f->seal($first); $firstReceipt = $f->services($first)->publisher->publish($first);
            $resolved = $f->resolve(D\SnapshotSelector::active()); // The only latest lookup for this retrieval.
            [$set,$lease] = $f->second(); $second = $f->request(502,'1','second',$set,$lease); $f->seal($second);
            $f->services($second)->publisher->publish($second);
            self::assertSame('retired',$f->manifest->state(501)); self::assertSame('active',$f->manifest->state(502));
            $pinned = $f->resolve(D\SnapshotSelector::exact($resolved->ref));
            self::assertSame(Jcs::canonicalize($resolved->ref->toWire()),Jcs::canonicalize($pinned->ref->toWire()));
            self::assertSame($firstReceipt->toWire()->get('snapshotId'),$pinned->ref->snapshotId->value);
            $scope = $first->lease->scope; $binding = str_repeat('b',64);
            $grant = new D\QueryGrant(new JsonObject(['allowedCollectionIds'=>[], 'allowedSourceVersionIds'=>[Fixture::id(3),Fixture::id(30)],
                'grantEpoch'=>$scope->policyEpoch->value,'policyBindingDigest'=>$binding,'expiresAt'=>'2026-11-06T12:00:05Z']),PortFixture::validator());
            $search = D\AuthorizedSearchScope::materialize($scope,$grant,$pinned->ref,$pinned->generationIds[0],
                [new D\SourceVersionId(Fixture::id(3))],new D\UtcInstant('2026-11-06T12:00:01Z'));
            $catalog = $f->manifest->jobs->catalog;
            $index = new PdoLexicalIndex($f->db(),$catalog->tx,PortFixture::validator(),new CanonicalChunkSets([$f->manifest->set,$set]),new TestIds());
            $found = $index->search($search,new D\Query('gravity'),10);
            self::assertCount(1,$found->candidates); self::assertSame(Fixture::id(3),$found->candidates[0]->ref->revisionRef->sourceRef->sourceVersionId->value);
            Fixture::failure(fn() => $first->candidate->assertCandidate($set->chunkSet->chunks[0]->ref),'candidate_not_in_manifest');
            // Authoritative current usability applies even to the pinned retired snapshot.
            $f->db()->execute("UPDATE source_version_state SET usability='revoked' WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(3)]);
            $report = $catalog->tx->run(function() use ($catalog,$scope): D\UsabilityReport {
                $catalog->tx->lockTenant($scope->tenantId,D\LockMode::Share);
                return (new PdoDependencyStore($catalog->db,$catalog->tx,$scope->tenantId,$catalog->ids))->checkAncestors(
                    new D\NodeRefs($scope->tenantId,[new D\ResourceRef($scope->tenantId,'source_version',new D\SourceVersionId(Fixture::id(3)))]));
            });
            self::assertSame(D\Decision::Deny,$report->decision);
            $current = D\AuthorizedSearchScope::materialize($scope,$grant,$pinned->ref,$pinned->generationIds[0],
                $report->decision === D\Decision::Allow ? [new D\SourceVersionId(Fixture::id(3))] : [],new D\UtcInstant('2026-11-06T12:00:01Z'));
            self::assertSame([],$index->search($current,new D\Query('gravity'),10)->candidates);
            Fixture::outcome('R06-A01',['selectedVersion'=>Fixture::id(3),'mixedNewRefs'=>false]);
            Fixture::outcome('R06-A02',['pinnedUnchanged'=>true,'revokedHistory'=>'denied']);
        });
    }

    public function testTenantAndCrossOperationIdempotencyCannotBeConfused(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(key:'create-kb'); $f->seal($r);
            Fixture::failure(fn() => $f->services($r)->publisher->publish($r),'idempotency_conflict');
            $l = $r->lease; $s = $l->scope;
            $foreign = new D\Scope(new D\TenantId(Fixture::id(9)),$s->kbId,$s->actorId,$s->purpose,$s->policyEpoch,$s->hostScopeHash);
            $source = $l->sourceRef;
            $lease = new D\Lease($foreign,$l->jobId,$l->attemptId,new D\SourceVersionRef($foreign->tenantId,$source->sourceId,$source->sourceVersionId,$source->sourceDigest),
                null,$l->fence,str_repeat('x',32),$l->leaseUntil,$l->attemptDeadline,$l->sourceEpoch);
            Fixture::failure(fn() => new D\ActivationRequest($lease,$r->candidate,$r->expectedPointerVersion,$r->key),'scope_mismatch',D\Outcome::Denied);
            $other = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction($f->db());
            try { $f->services($r)->staging->checkPublication($r,$other); self::fail('Mismatched transaction accepted'); }
            catch (\InvalidArgumentException $error) { self::assertSame('Shared publication transaction required',$error->getMessage()); }
            self::assertNull($f->manifest->pointer());
        });
    }
}
