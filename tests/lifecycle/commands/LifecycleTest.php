<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Commands;

use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Adapter\Postgres\Lifecycle\PdoLifecycleStore;
use KnowKit\Application\Lifecycle\LifecycleGuarantee;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:commands')]
final class LifecycleTest extends TestCase
{
    public function testOrdinaryVersionPreservesAuthorizedHistoryAndExactCitation(): void
    {
        Fixture::run(function (Fixture $f): void {
            $usage = $f->recordHistoricalUsage();
            $before = $this->history($f);
            $version = $f->reserveAcceptedBlob(30);
            $f->lifecycle->publishOrdinaryVersion($f->scope(), $version, $f->catalog->context('ordinary', '0', '0'));
            $f->seedExtractedArtifacts(30, 2, 31, 32, 33, 34, 35, 36);
            $f->atomic(function () use ($f): void {
                $v = $f->registerNode('source_version', 30, [$f->ref('source', 2)]);
                $r = $f->registerNode('document_revision', 31, [$v->ref]);
                $f->registerNode('chunk', 36, [$r->ref]);
            });
            $f->sealSnapshot(120, 121, 2, 30, 31, 35, 36);
            $f->lifecycle->publishSnapshot($f->scope(), $version->ref, new D\SnapshotId(Fixture::id(120)),
                new D\Decimal('0'), new D\Decimal('1'), new D\IdempotencyKey('ordinary-publish'));
            self::assertSame(Fixture::id(120), $f->catalog->repo->getKb($f->tenant, $f->scope()->kbId)->activeSnapshotId->value);
            $f->epochs->read($f->scope(), [$f->versionRef(3)]); // Explicit authorized historical v1, immediately before use.
            self::assertSame(D\Decision::Allow, $f->atomic(fn () => $f->decision($f->ref('chunk', 11))));
            self::assertSame($before, $this->history($f));
            self::assertStringContainsString(Fixture::id(3), $usage->canonical());
            self::assertSame(0, $f->catalog->count('usage_invalidations'));
            self::assertSame('0', $this->state($f)->lifecycleEpoch->value);
            $citation = $f->catalog->db->row('SELECT source_version_id FROM citations');
            $oldState = $f->atomic(fn () => $f->store->getVersionState($f->scope(),new D\SourceVersionId(Fixture::id(3))));
            Fixture::assertOutcome('R29-A01', ['activeSnapshot'=>$f->catalog->repo->getKb($f->tenant,$f->scope()->kbId)->activeSnapshotId->value === Fixture::id(120) ? 'v2' : 'unexpected',
                'historicVersion'=>$f->catalog->repo->getSourceVersion($f->tenant,new D\SourceVersionId(Fixture::id(3)))->ref->sourceVersionId->value === Fixture::id(3) ? 'v1' : 'unexpected',
                'historicUsability'=>$oldState->usability->value, 'citationVersion'=>$citation['source_version_id'] === Fixture::id(3) ? 'v1' : 'unexpected']);
        });
    }

    public function testCorrectionChangesOnlyExactVersionAndMarkersPreserveHistory(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->recordHistoricalUsage(); $before = $this->history($f);
            $replacement = $f->seedVersionWithGraph(30, 2, 31, 32, 33, 34, 35, 36);
            $f->sealSnapshot(120, 121, 2, 30, 31, 35, 36);
            $f->lifecycle->correct($f->scope(), new D\Correction($f->versionRef(3), $replacement, new D\Decimal('0')), new D\IdempotencyKey('correction'));
            self::assertSame('1', $this->state($f)->lifecycleEpoch->value);
            self::assertSame(D\Decision::Deny, $f->atomic(fn () => $f->decision($f->ref('chunk', 11))));
            self::assertSame(D\Decision::Allow, $f->atomic(fn () => $f->decision($f->ref('chunk', 36))));
            Fixture::failure(fn () => $f->epochs->read($f->scope(), [$f->versionRef(3)]), 'source_unusable', D\Outcome::Denied);
            self::assertSame($before, $this->history($f));
            $marker = $f->catalog->db->row('SELECT usage_id,source_version_id,event_id FROM usage_invalidations');
            self::assertSame(Fixture::id(16), $marker['usage_id']); self::assertSame(Fixture::id(3), $marker['source_version_id']);
            self::assertSame(Fixture::id(120), $f->catalog->repo->getKb($f->tenant, $f->scope()->kbId)->activeSnapshotId->value);
            self::assertSame(Fixture::id(30), $f->atomic(fn () => $f->store->getVersionState($f->scope(), new D\SourceVersionId(Fixture::id(3))))->correctedByVersionId->value);
            Fixture::assertOutcome('R29-A02', ['oldVersion'=>$f->atomic(fn () => $f->store->getVersionState($f->scope(),new D\SourceVersionId(Fixture::id(3))))->usability->value,
                'replacement'=>$f->atomic(fn () => $f->store->getVersionState($f->scope(),new D\SourceVersionId(Fixture::id(30))))->usability->value,
                'historyUnchanged'=>$before === $this->history($f), 'markerVersion'=>$marker['source_version_id'] === Fixture::id(3) ? 'v1' : 'unexpected']);
        });
    }

    public function testUrgentCorrectionBlocksBeforeReplacementAndKeepsSourceActive(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->recordHistoricalUsage(); $before = $this->history($f);
            $pending = $f->reserveAcceptedBlob(30);
            $f->lifecycle->correct($f->scope(), new D\Correction($f->versionRef(3), null, new D\Decimal('0')), new D\IdempotencyKey('urgent'));
            self::assertSame(D\SourceLifecycleState::Active, $this->state($f)->lifecycleState);
            self::assertSame(D\Decision::Deny, $f->atomic(fn () => $f->decision($f->ref('chunk', 11))));
            Fixture::failure(fn () => $f->epochs->read($f->scope(), [$f->versionRef(3)]), 'source_unusable', D\Outcome::Denied);
            self::assertNull($f->catalog->repo->getSourceVersion($f->tenant, $pending->ref->sourceVersionId));
            self::assertSame($before, $this->history($f));
            Fixture::assertOutcome('R29-A03', ['oldVersionUse'=>strtolower($f->atomic(fn () => $f->decision($f->ref('chunk',11)))->value) === 'deny' ? 'denied' : 'unexpected',
                'source'=>$this->state($f)->lifecycleState->value,
                'replacementReady'=>$f->catalog->repo->getSourceVersion($f->tenant,$pending->ref->sourceVersionId) !== null]);
            $f->lifecycle->publishOrdinaryVersion($f->scope(), $pending, $f->catalog->context('later-safe', '1', '1'));
            self::assertNotNull($f->catalog->repo->getSourceVersion($f->tenant, $pending->ref->sourceVersionId));
        });
    }

    public function testAcceptAndCorrectIsAtomicAndRejectsUnreadyReplacement(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->recordHistoricalUsage(); $before = $this->history($f); $events = $f->catalog->count('outbox');
            $replacement = $f->reserveAcceptedBlob(30);
            $command = new D\Correction($f->versionRef(3), $replacement->ref, new D\Decimal('0'));
            Fixture::failure(fn () => $f->lifecycle->acceptAndCorrect($f->scope(), $replacement, $command, new D\IdempotencyKey('atomic')), 'replacement_not_ready');
            self::assertNull($f->catalog->repo->getSourceVersion($f->tenant, $replacement->ref->sourceVersionId));
            self::assertSame($events, $f->catalog->count('outbox')); self::assertSame('0', $this->state($f)->lifecycleEpoch->value);
            $stage = function () use ($f): void {
                $f->seedExtractedArtifacts(30, 2, 31, 32, 33, 34, 35, 36);
                $v = $f->registerNode('source_version', 30, [$f->ref('source', 2)]);
                $r = $f->registerNode('document_revision', 31, [$v->ref]); $f->registerNode('chunk', 36, [$r->ref]);
                $f->sealSnapshot(120, 121, 2, 30, 31, 35, 36);
            };
            $f->lifecycle->acceptAndCorrect($f->scope(), $replacement, $command, new D\IdempotencyKey('atomic'), $stage);
            self::assertSame($events + 3, $f->catalog->count('outbox')); // accepted, corrected, activated
            self::assertSame('1', $this->state($f)->lifecycleEpoch->value);
            self::assertSame($before, $this->history($f));
            $f->lifecycle->acceptAndCorrect($f->scope(), $replacement, $command, new D\IdempotencyKey('atomic'),
                static function (): void { throw new \RuntimeException('Replay repeated staging'); });
            self::assertSame($events + 3, $f->catalog->count('outbox'));
        });
    }

    public function testLegalHoldRevokeDeleteAndReleaseNeverGrantReadsOrEraseBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->recordHistoricalUsage(); $history = $this->history($f);
            $f->lifecycle->placeLegalHold($f->scope(), new D\LegalHold(new D\SourceId(Fixture::id(2)), true, new D\Decimal('0')), new D\IdempotencyKey('hold'));
            $f->lifecycle->revoke($f->scope(), new D\Revocation(new D\SourceId(Fixture::id(2)), new D\Decimal('1')), new D\IdempotencyKey('revoke'));
            $inventory = $f->catalog->db->execute('SELECT id,digest,state FROM resource_ledger ORDER BY id')->fetchAll();
            $task = $f->lifecycle->requestPurge($f->scope(), new D\PurgeRequest(new D\SourceId(Fixture::id(2)), new D\Decimal('2')), new D\IdempotencyKey('delete'));
            $row = $f->catalog->db->row('SELECT * FROM deletion_tasks WHERE id=?', [$task->value]);
            self::assertSame('pending', $row['status']); self::assertSame('legal_hold', $row['hold_reason']);
            self::assertSame(3, $f->catalog->count('deletion_task_resources'));
            self::assertSame($inventory, $f->catalog->db->execute('SELECT id,digest,state FROM resource_ledger ORDER BY id')->fetchAll());
            CatalogFixture::sqlReject($f->catalog->db->pdo, "UPDATE resource_ledger SET state='deleting' WHERE id=?", [Fixture::id(3003)]);
            $f->lifecycle->placeLegalHold($f->scope(), new D\LegalHold(new D\SourceId(Fixture::id(2)), false, new D\Decimal('3')), new D\IdempotencyKey('release'));
            self::assertSame(D\SourceLifecycleState::DeletionRequested, $this->state($f)->lifecycleState);
            self::assertFalse($this->state($f)->retentionHold);
            self::assertSame(D\Decision::Deny, $f->atomic(fn () => $f->decision($f->ref('chunk', 11))));
            Fixture::failure(fn () => $f->epochs->read($f->scope(), [$f->versionRef(3)]), 'source_unusable', D\Outcome::Denied);
            self::assertSame($history, $this->history($f));
        });
    }

    public function testAccessRevokeAtomicallyInvalidatesEpochAndConsentIdentity(): void
    {
        Fixture::run(function (Fixture $f): void {
            $source = new D\SourceId(Fixture::id(2)); $key = new D\IdempotencyKey('consent');
            $binding = $f->epochs->read($f->scope(), [$f->versionRef(3)]);
            self::assertSame('1', $f->lifecycle->revokeAccess($f->scope(), $source, new D\Decimal('0'), new D\Decimal('0'), $key)->value);
            self::assertSame('1', $f->lifecycle->revokeAccess($f->scope(), $source, new D\Decimal('0'), new D\Decimal('0'), $key)->value);
            $state = $this->state($f); self::assertSame('1', $state->lifecycleEpoch->value); self::assertSame('1', $state->sourcePolicyEpoch->value);
            self::assertNotSame($binding->get('sources')[0]->get('sourcePolicyEpoch'), $state->sourcePolicyEpoch->value);
            self::assertSame('consent_revoked', $f->atomic(fn () => $f->store->getTombstone($f->scope(), $source))->reason);
            Fixture::failure(fn () => $f->epochs->read($f->scope(), [$f->versionRef(3)]), 'source_unusable', D\Outcome::Denied);
            Fixture::failure(fn () => $f->lifecycle->revokeAccess($f->scope(), $source, new D\Decimal('1'), new D\Decimal('0'), $key), 'idempotency_conflict');
        });
    }

    public function testEventReplayAndConflictingEventIdPreserveOneEffect(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->recordHistoricalUsage();
            $command = new D\Revocation(new D\SourceId(Fixture::id(2)), new D\Decimal('0'));
            $f->lifecycle->revoke($f->scope(), $command, new D\IdempotencyKey('revoke'));
            $row = $f->catalog->db->row("SELECT event_json FROM outbox WHERE event_json->>'type'='source.revoked'");
            $event = new D\DomainEvent(StrictJson::decode($row['event_json']), PortFixture::validator());
            $counts = [$f->catalog->count('outbox'), $f->catalog->count('usage_invalidations'), $f->catalog->count('tombstones')];
            $f->atomic(fn () => $f->graph->invalidate(new D\LifecycleEvent($event)));
            $f->lifecycle->revoke($f->scope(), $command, new D\IdempotencyKey('revoke'));
            self::assertSame($counts, [$f->catalog->count('outbox'), $f->catalog->count('usage_invalidations'), $f->catalog->count('tombstones')]);
            self::assertSame('1', $this->state($f)->lifecycleEpoch->value);
            $payload = $event->toWire()->get('payload')->with('reason', 'consent_revoked');
            $conflict = new D\DomainEvent($event->toWire()->with('payload', $payload)->with('payloadDigest', HashDomain::DomainEventPayload->digest($payload)), PortFixture::validator());
            Fixture::failure(fn () => $f->atomic(fn () => $f->graph->invalidate(new D\LifecycleEvent($conflict))), 'idempotency_conflict');
            self::assertSame($counts, [$f->catalog->count('outbox'), $f->catalog->count('usage_invalidations'), $f->catalog->count('tombstones')]);
            self::assertSame(0, $f->catalog->count('inbox')); // P3.5 owns delivery and inbox.
        });
    }

    public function testRollbackAndUnknownDeniedUnavailableIncompatibleRemainDistinct(): void
    {
        Fixture::run(function (Fixture $f): void {
            $source = new D\SourceId(Fixture::id(2)); $events = $f->catalog->count('outbox');
            foreach ([[D\Decision::Deny,D\Outcome::Denied], [D\Decision::Unknown,D\Outcome::Unknown]] as [$decision,$outcome]) {
                $f->catalog->owner->decision = $decision;
                try { $f->lifecycle->revoke($f->scope(), new D\Revocation($source, new D\Decimal('0')), new D\IdempotencyKey('denied')); self::fail('Owner failure accepted'); }
                catch (D\DomainFailure $e) { self::assertSame($outcome, $e->outcome); }
                self::assertSame($events, $f->catalog->count('outbox'));
            }
            $f->catalog->owner->decision = D\Decision::Allow;
            $f->catalog->db->execute("UPDATE tenants SET serving_state='blocked' WHERE id=?", [$f->tenant->value]);
            Fixture::failure(fn () => $f->lifecycle->revoke($f->scope(), new D\Revocation($source, new D\Decimal('0')), new D\IdempotencyKey('blocked')),
                'tenant_blocked', D\Outcome::Unavailable);
            $f->catalog->db->execute("UPDATE tenants SET serving_state='ready' WHERE id=?", [$f->tenant->value]);
            $resource = new D\ResourceRef($f->tenant, 'source', $source);
            $f->catalog->authority->prepare($f->scope(), $resource, D\Operation::LifecycleRevoke);
            try {
                $f->atomic(function () use ($f, $source): void {
                    $f->catalog->tx->lockKb($f->scope()->kbId, D\LockMode::Update);
                    $f->catalog->tx->lockSources([$source], D\LockMode::Update);
                    $f->store->executeIdempotent($f->scope(), $source, D\Operation::LifecycleRevoke, new D\IdempotencyKey('rollback'), new JsonObject(), new D\Decimal('0'), function () use ($f,$source): JsonObject {
                        self::assertTrue($f->store->revokeIdentity($f->scope(), new D\Revocation($source, new D\Decimal('0'))));
                        throw new \RuntimeException('after-outbox');
                    });
                });
                self::fail('Injected failure missed');
            } catch (\RuntimeException $e) { self::assertSame('after-outbox', $e->getMessage()); }
            self::assertSame($events, $f->catalog->count('outbox')); self::assertSame(0, $f->catalog->count('tombstones'));
            self::assertSame('0', $this->state($f)->lifecycleEpoch->value);
            $foreign = new D\SourceVersionRef(new D\TenantId(Fixture::id(99)), $source, new D\SourceVersionId(Fixture::id(3)), $f->versionRef(3)->sourceDigest);
            Fixture::failure(fn () => $f->lifecycle->correct($f->scope(), new D\Correction($foreign, null, new D\Decimal('0')), new D\IdempotencyKey('foreign')), 'scope_mismatch', D\Outcome::Denied);
            $wrong = new D\SourceVersionRef($f->tenant, $source, new D\SourceVersionId(Fixture::id(3)), new D\SourceDigest(str_repeat('f',64)));
            Fixture::failure(fn () => $f->lifecycle->correct($f->scope(), new D\Correction($wrong, null, new D\Decimal('0')), new D\IdempotencyKey('wrong')), 'immutable_conflict');
        });
    }

    public function testStaleWorkerIsFencedBeforeItCanAcceptResults(): void
    {
        Fixture::run(function (Fixture $f): void {
            $processing = PortFixture::processing(PortFixture::fixture()->get('base')->with('sourceVersionId', Fixture::id(3))->with('sourceDigest', $f->versionRef(3)->sourceDigest->value));
            $queue = new PdoJobQueue($f->catalog->db, $f->catalog->tx, $f->scope(), $f->catalog->ids, ['extract'=>['extract']],
                static fn () => throw new \RuntimeException('Unexpected checkpoint read'));
            $job = $queue->enqueue(new D\JobCommand($f->scope(), $f->versionRef(3), null, new D\JobType('extract'), $processing,
                new D\UtcInstant('2026-01-01T00:00:00Z')), new D\IdempotencyKey('worker'));
            $lease = $queue->lease(new D\JobType('extract'), new D\WorkerId(Fixture::id(900)));
            self::assertNotNull($lease);
            $f->lifecycle->revoke($f->scope(), new D\Revocation(new D\SourceId(Fixture::id(2)), new D\Decimal('0')), new D\IdempotencyKey('worker-revoke'));
            $row = $f->catalog->db->row('SELECT state,fence,cancel_requested,uncertain_external_effect FROM jobs WHERE id=?', [$job->value]);
            self::assertSame('cancelled', $row['state']); self::assertSame($lease->fence->next()->value, (string)$row['fence']);
            self::assertTrue($row['cancel_requested']); self::assertTrue($row['uncertain_external_effect']);
            $effect = false;
            self::assertFalse($queue->accept($lease, new D\JobResult(D\Outcome::Success, new D\Sha256(str_repeat('a',64))), function () use (&$effect): void { $effect = true; }));
            self::assertFalse($effect);
        });
    }

    public function testCrossKbRevocationBlocksEveryMembershipAndFutureVersion(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->secondKb(90); $f->addMembership(2,90,'second-membership');
            $future = $f->reserveAcceptedBlob(30);
            $f->lifecycle->revoke($f->scope(),new D\Revocation(new D\SourceId(Fixture::id(2)),new D\Decimal('0')),new D\IdempotencyKey('all-kbs'));
            foreach ([90,100] as $kb) {
                Fixture::failure(fn () => $f->epochs->read($f->scope($kb),[$f->versionRef(3)]),'source_unusable',D\Outcome::Denied);
            }
            Fixture::failure(fn () => $f->lifecycle->publishOrdinaryVersion($f->scope(),$future,$f->catalog->context('stale-version','1','1')),'source_revoked',D\Outcome::Denied);
            self::assertNull($f->catalog->repo->getSourceVersion($f->tenant,$future->ref->sourceVersionId));
            self::assertSame(1,$f->catalog->count('tombstones'));
        });
    }

    public function testDirectDeleteIncludesUnknownOwnedInventoryAndExcludesExternalBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->catalog->resource(900,2,1,'external'); $f->catalog->resource(901);
            $f->catalog->db->execute("UPDATE resource_ledger SET state='unknown' WHERE id=?",[Fixture::id(901)]);
            $task = $f->lifecycle->requestPurge($f->scope(),new D\PurgeRequest(new D\SourceId(Fixture::id(2)),new D\Decimal('0')),new D\IdempotencyKey('direct-delete'));
            $row = $f->catalog->db->row('SELECT * FROM deletion_tasks WHERE id=?',[$task->value]);
            self::assertSame('unknown',$row['status']); self::assertSame(4,$f->catalog->count('deletion_task_resources'));
            self::assertStringNotContainsString(Fixture::id(900),$row['required_resources_json']);
            self::assertSame(HashDomain::ResourceInventory->digest(StrictJson::decode($row['required_resources_json'])),$row['inventory_digest']);
            self::assertSame('source_deleted',$f->atomic(fn () => $f->store->getTombstone($f->scope(),new D\SourceId(Fixture::id(2))))->reason);
            self::assertSame(D\Decision::Deny,$f->atomic(fn () => $f->decision($f->ref('chunk',11))));
            self::assertSame(0,(int)$f->catalog->db->execute("SELECT count(*) FROM resource_ledger WHERE state='deleted'")->fetchColumn());
            CatalogFixture::sqlReject($f->catalog->db->pdo,"UPDATE deletion_tasks SET status='done' WHERE id=?",[$task->value]);
        });
    }

    public function testHoldTooLateAndFreshnessBoundsFailClosed(): void
    {
        Fixture::run(function (Fixture $f): void {
            $before = $f->catalog->count('outbox');
            $f->catalog->db->execute("UPDATE resource_ledger SET state='deleting' WHERE id=?",[Fixture::id(3003)]);
            Fixture::failure(fn () => $f->lifecycle->placeLegalHold($f->scope(),new D\LegalHold(new D\SourceId(Fixture::id(2)),true,new D\Decimal('0')),new D\IdempotencyKey('too-late')),'HOLD_TOO_LATE');
            self::assertSame($before,$f->catalog->count('outbox')); self::assertSame('0',$this->state($f)->lifecycleEpoch->value);
            $receipt = $f->catalog->owner->decide($f->scope(),$f->ref('source',2),D\Operation::LifecycleRevoke);
            $f->lifecycle->guarantee()->requireFreshReceipt($receipt,new D\UtcInstant('2026-11-06T12:00:04Z'));
            Fixture::failure(fn () => $f->lifecycle->guarantee()->requireFreshReceipt($receipt,new D\UtcInstant('2026-11-06T12:00:05Z')),'owner_freshness_unknown',D\Outcome::Unknown);
            Fixture::failure(fn () => (new LifecycleGuarantee())->requireFreshReceipt($receipt,new D\UtcInstant('2026-11-06T12:00:01Z')),'owner_freshness_unknown',D\Outcome::Unknown);
        });
    }

    public function testCorrectionCannotSwitchToSnapshotDroppingUnaffectedMembers(): void
    {
        foreach ([false,true] as $keepOther) {
            Fixture::run(function (Fixture $f) use ($keepOther): void {
                $f->catalog->source(50); $f->addMembership(50,100,'other-source');
                $f->seedVersionWithGraph(51,50,52,53,54,55,56,57);
                $other = [[50,51,52,56,57]];
                $f->sealSnapshot(110,14,2,3,4,10,11,100,$other); $f->activateSnapshot(110);
                $replacement = $f->seedVersionWithGraph(30,2,31,32,33,34,35,36);
                $f->sealSnapshot(120,121,2,30,31,35,36,100,$keepOther ? $other : []);
                $f->lifecycle->correct($f->scope(),new D\Correction($f->versionRef(3),$replacement,new D\Decimal('0')),new D\IdempotencyKey('preserve-others'));
                self::assertSame(Fixture::id($keepOther ? 120 : 110),$f->catalog->repo->getKb($f->tenant,$f->scope()->kbId)->activeSnapshotId->value);
                self::assertSame(D\Decision::Deny,$f->atomic(fn () => $f->decision($f->ref('chunk',11))));
                self::assertSame(D\Decision::Allow,$f->atomic(fn () => $f->decision($f->ref('chunk',57))));
            });
        }
    }

    public function testMissingOwnerAuthorityAndStaleEpochCannotMutate(): void
    {
        Fixture::run(function (Fixture $f): void {
            $store = new PdoLifecycleStore($f->catalog->db,$f->catalog->tx,$f->tenant,$f->catalog->clock,$f->catalog->ids,PortFixture::validator(),$f->catalog->events->create(...));
            $source = new D\SourceId(Fixture::id(2)); $events = $f->catalog->count('outbox');
            Fixture::failure(fn () => $f->atomic(fn () => $store->executeIdempotent($f->scope(),$source,D\Operation::LifecycleRevoke,
                new D\IdempotencyKey('no-owner'),new JsonObject(),new D\Decimal('0'),static fn () => throw new \RuntimeException('Unauthorized effect'))),
                'policy_unknown',D\Outcome::Unknown);
            Fixture::failure(fn () => $f->lifecycle->revoke($f->scope(),new D\Revocation($source,new D\Decimal('9')),new D\IdempotencyKey('stale-epoch')),
                'POLICY_CHANGED',D\Outcome::Denied);
            self::assertSame($events,$f->catalog->count('outbox')); self::assertSame('0',$this->state($f)->lifecycleEpoch->value);
        });
    }

    public function testTransactionDeadlineRollsBackCatalogTombstoneAndOutbox(): void
    {
        Fixture::run(function (Fixture $f): void {
            $events = $f->catalog->count('outbox');
            Fixture::failure(fn () => $f->atomic(function () use ($f): void {
                $source = new D\SourceId(Fixture::id(2));
                $f->catalog->tx->lockKb($f->scope()->kbId,D\LockMode::Update);
                $f->catalog->tx->lockSources([$source],D\LockMode::Update);
                $f->store->lockWork($f->scope(),$source);
                self::assertTrue($f->store->revokeIdentity($f->scope(),new D\Revocation($source,new D\Decimal('0'))));
                usleep(2_050_000); // Exercise wall time across SQL statements, not statement_timeout alone.
            }), 'transaction_deadline',D\Outcome::Unavailable);
            self::assertSame($events,$f->catalog->count('outbox')); self::assertSame(0,$f->catalog->count('tombstones'));
            self::assertSame('0',$this->state($f)->lifecycleEpoch->value);
        });
    }

    private function state(Fixture $f): D\Source
    { return $f->atomic(fn () => $f->store->getSourceState($f->scope(), new D\SourceId(Fixture::id(2)))); }

    private function history(Fixture $f): array
    {
        return [$f->catalog->db->execute('SELECT * FROM evidence_usages ORDER BY id')->fetchAll(),
            $f->catalog->db->execute('SELECT * FROM citations ORDER BY id')->fetchAll(),
            $f->catalog->db->execute('SELECT * FROM usage_refs ORDER BY id')->fetchAll(),
            $f->catalog->db->execute('SELECT * FROM source_versions WHERE id=?', [Fixture::id(3)])->fetchAll(),
            $f->catalog->db->execute('SELECT * FROM chunks WHERE id=?', [Fixture::id(11)])->fetchAll()];
    }
}
