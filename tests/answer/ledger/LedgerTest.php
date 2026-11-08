<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Ledger;

use KnowKit\Answer\Application\InvocationAdmission;
use KnowKit\Answer\Application\InvocationRecovery;
use KnowKit\Answer\Application\InvocationReplay;
use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:ledger')]
final class LedgerTest extends TestCase
{
    private function fails(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected ' . $code);
        } catch (D\DomainFailure $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public function testSameKeySameRequestReplaysOneCommittedResponseWithoutAnotherSend(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $completed = $f->finish();
            self::assertSame(A\InvocationState::Completed, $completed->state);
            self::assertSame('3', $completed->claim->rowVersion->value);
            self::assertEquals($completed, $f->admit());
            self::assertEquals($f->outcome(), $f->replay());
            self::assertEquals($f->outcome(), $f->replay());
            self::assertSame(1, $f->resolver->calls);
            self::assertSame(1, $f->transport->sends);
            self::assertSame(4, $f->authority->replays);
            self::assertSame(2, $f->payloads->responseReads);
            self::assertSame(1, $f->blobs->catalog->count('knowkit_answer_invocations'));
            $this->fails(fn () => $f->ledger->complete($completed->claim, $f->outcome()), 'stale_claim');
            $this->fails(fn () => $f->ledger->markUnknown($completed->claim), 'stale_claim');
        });
    }

    public function testChangedOriginalBodyActorAudienceHistoryProfileAndLimitsConflict(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $f->admit();
            $s = $f->scope();
            $scopes = [
                new D\Scope($s->tenantId, $s->kbId, new D\ActorId(BlobFixture::id(999)), $s->purpose, $s->policyEpoch, $s->hostScopeHash),
                new D\Scope($s->tenantId, $s->kbId, $s->actorId, 'answer.other', $s->policyEpoch, $s->hostScopeHash),
                new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, new D\Sha256(str_repeat('f', 64))),
                new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch->next(), $s->hostScopeHash),
            ];
            $changes = [
                ['question' => 'Changed protected body'],
                ['priorTurns' => new A\BoundedTurns()],
                ['modelBindingRef' => new A\ModelBindingRef('tutor:other')],
                ['retrievalProfileRef' => new A\ProfileRef('retrieval:lesson', '8', new D\Sha256(str_repeat('b', 64)))],
                ['limits' => new A\AnswerLimits(outputTokens: 100)],
                ...array_map(static fn (D\Scope $scope): array => ['scope' => $scope], $scopes),
            ];
            foreach ($changes as $change) {
                $this->fails(fn () => (new InvocationAdmission($f->ledger, $f->resolver))->admit($f->request($change)), 'invocation_conflict');
            }
            self::assertSame(1, $f->resolver->calls);
            self::assertSame(0, $f->transport->sends);
            $foreign = new A\InvocationRef(new D\TenantId(BlobFixture::id(8)), $f->request()->invocationKey);
            $this->fails(fn () => $f->ledger->find($foreign), 'scope_mismatch');
        });
    }

    public function testConcurrentFirstAdmissionsPinOnlyWinningBindingAcrossTwoConnections(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $db = $f->secondConnection();
            $other = $f->makeLedger('worker:b', 120, $db);
            $binding = $f->binding(['model' => 'winner-model', 'bindingDigest' => new D\Sha256(str_repeat('e', 64))]);
            $resolver = new BindingResolver($binding, $db);
            $winner = null;
            // Both lookups see absent. B inserts after A resolves/saves pins, before A insert-CAS.
            $f->payloads->afterPins = function () use ($f, $other, $resolver, &$winner): void {
                $winner = (new InvocationAdmission($other, $resolver))->admit($f->request());
            };
            $loser = $f->admit();
            self::assertEquals($winner, $loser);
            self::assertSame('winner-model', $loser->pins->binding->model);
            self::assertSame($f->request()->fingerprint($binding)->value, $loser->requestFingerprint->value);
            self::assertSame('worker:b', $loser->claim->leaseOwner);
            self::assertSame(1, $f->resolver->calls);
            self::assertSame(1, $resolver->calls);
            self::assertSame(1, $f->blobs->catalog->count('knowkit_answer_invocations'));
            self::assertSame(2, $f->blobs->catalog->count('knowkit_answer_payloads'));
            $this->fails(fn () => $f->ledger->reserve($loser->claim, $f->requestBytes(),
                new A\BudgetReservation($loser->invocationRef, 'budget:loser', A\ReservationStatus::Confirmed)), 'stale_claim');
            self::assertSame(0, $f->transport->sends);
        });
    }

    public function testChangedTutorAndCredentialHandlesOnRetryNeverResolveMutableBinding(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $first = $f->admit();
            $f->resolver->binding = $f->binding(['model' => 'new-model', 'credentialRef' => new D\CredentialRef('credential:rotated', true),
                'bindingDigest' => new D\Sha256(str_repeat('9', 64))]);
            $f->resolver->fail = true;
            self::assertEquals($first, $f->admit());
            self::assertSame('chat-model', $f->admit()->pins->binding->model);
            self::assertSame(1, $f->resolver->calls);
            $request = $f->requestBytes();
            $reserved = $f->ledger->reserve($first->claim, $request,
                new A\BudgetReservation($first->invocationRef, 'budget:001', A\ReservationStatus::Confirmed));
            $f->authority->credentialsValid = false;
            $denial = $f->dispatcher()->send($reserved->claim, $request, Fixture::cancellation());
            self::assertInstanceOf(A\AnswerOutcome::class, $denial);
            self::assertSame(A\OutcomeCode::Denied, $denial->code);
            self::assertSame(0, $f->transport->sends);
            self::assertSame(A\InvocationState::Completed, $f->ledger->find($first->invocationRef)->state);
        });
    }

    public function testCrashBeforeIntentResumesKnownNotSentWithFreshPolicyAndHigherFence(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            self::assertSame(A\DispatchCertainty::KnownNotSent, $reserved->dispatchCertainty);
            self::assertNull($reserved->modelCallIdentityDigest);
            $f->expireLease();
            $other = $f->makeLedger('worker:b');
            $recovered = (new InvocationRecovery($other, $f->authority))->resume($f->request(), $f->scope());
            self::assertInstanceOf(A\InvocationRecord::class, $recovered);
            self::assertSame(A\InvocationState::Reserved, $recovered->state);
            self::assertSame('1', $recovered->claim->fencingEpoch->value);
            self::assertSame(1, $f->authority->resumes);
            $this->fails(fn () => $f->ledger->markSending($reserved->claim, $f->requestBytes()->identity), 'stale_claim');
            $f->dispatcher($other)->send($recovered->claim, $f->requestBytes(), Fixture::cancellation());
            $sending = $other->find($recovered->invocationRef);
            $other->complete($sending->claim, $f->outcome());
            self::assertSame(1, $f->transport->sends);
            self::assertSame(A\OutcomeCode::Answered, $f->replay()->code);
        }, 1);
    }

    public function testCrashAfterIntentWithoutAnyDispatchBecomesUnknownAndNeverResends(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $request = $f->requestBytes();
            $sending = $f->ledger->markSending($reserved->claim, $request->identity);
            self::assertEquals($request->identity->digest(), $sending->modelCallIdentityDigest);
            self::assertSame(A\DispatchCertainty::Unknown, $sending->dispatchCertainty);
            $f->expireLease();
            $f->authority->historyVisible = false;
            $other = $f->makeLedger('worker:b');
            $unknown = (new InvocationRecovery($other, $f->authority))->resume($f->request(), $f->scope());
            self::assertSame(A\InvocationState::Unknown, $unknown->state);
            $this->fails(fn () => $f->dispatcher($other)->send($unknown->claim, $request, Fixture::cancellation()), 'stale_claim');
            $this->fails(fn () => $other->complete($unknown->claim, $f->outcome()), 'stale_claim');
            self::assertSame(A\OutcomeCode::TransportUnknown, $f->replay()->code);
            self::assertSame(0, $f->transport->sends);
            self::assertSame(0, $f->payloads->responseReads);
        }, 1);
    }

    public function testCrashAfterProviderReplyBeforeDurableCompletionDoesNotGenerateAgain(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $reply = $f->dispatcher()->send($reserved->claim, $f->requestBytes(), Fixture::cancellation());
            self::assertInstanceOf(A\TransportResult::class, $reply);
            self::assertTrue($reply->complete);
            self::assertSame('Synthetic protected response', $reply->text);
            self::assertNull($f->row()['response_payload_ref']);
            $f->expireLease();
            $other = $f->makeLedger('worker:b');
            $unknown = (new InvocationRecovery($other, $f->authority))->resume($f->request(), $f->scope());
            $this->fails(fn () => $f->dispatcher($other)->send($unknown->claim, $f->requestBytes(), Fixture::cancellation()), 'stale_claim');
            $outcome = $f->replay();
            self::assertNull($outcome->answeredContent());
            self::assertSame(A\OutcomeCode::TransportUnknown, $outcome->code);
            self::assertSame(1, $f->transport->sends);
            self::assertSame(0, $f->payloads->responseReads);
        }, 1);
    }

    public function testCompletedReplayChecksCurrentHistoryEgressAudienceAndSourceBeforePayloadRead(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $completed = $f->finish();
            $before = $f->row();
            $f->resolver->fail = true;
            $f->authority->historyVisible = false;
            self::assertSame(A\OutcomeCode::Denied, $f->replay()->code);
            $f->authority->historyVisible = true;
            $f->authority->egressAllowed = false;
            self::assertSame(A\OutcomeCode::EgressDenied, $f->replay()->code);
            $f->authority->egressAllowed = true;
            $s = $f->scope();
            $current = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch->next(), $s->hostScopeHash);
            $replay = new InvocationReplay($f->ledger, $f->payloads, $f->authority, PortFixture::validator());
            self::assertSame(A\OutcomeCode::Answered, $replay->replay($f->request(), $current)->code);
            $audience = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, new D\Sha256(str_repeat('0', 64)));
            $this->fails(fn () => $replay->replay($f->request(), $audience), 'scope_mismatch');
            $f->blobs->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
                [$s->tenantId->value, BlobFixture::id(3)]);
            $denied = $f->replay();
            self::assertNull($denied->answeredContent());
            self::assertSame(A\OutcomeCode::Denied, $denied->code);
            self::assertSame(A\DispatchCertainty::Sent, $denied->certainty);
            self::assertSame(1, $f->payloads->responseReads);
            self::assertSame($before, $f->row());
            self::assertEquals($completed->claim->rowVersion, $f->admit()->claim->rowVersion);
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testPurgedOrExpiredPayloadIsUnavailableWithoutResendOrResurrection(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $completed = $f->finish();
            $db = $f->blobs->catalog->db;
            $r = $db->row('SELECT * FROM resource_ledger WHERE tenant_id=? AND id=?', [$f->scope()->tenantId->value, $completed->responsePayloadRef]);
            self::assertNotNull($r['retention_until']);
            self::assertSame(1, (int) $r['ref_count']);
            unlink($f->blobs->root . '/' . $r['storage_key']);
            $before = $f->row();
            $unavailable = $f->replay();
            self::assertSame(A\OutcomeCode::Unavailable, $unavailable->code);
            self::assertSame(A\DispatchCertainty::Sent, $unavailable->certainty);
            self::assertNull($f->replay()->answeredContent());
            self::assertSame($before, $f->row());
            self::assertSame(1, $f->transport->sends);
            $f->clock->offset = 86401;
            $ref = new A\InvocationPayloadRef($f->scope()->tenantId, new D\ResourceId($completed->responsePayloadRef), $completed->responsePayloadHash);
            $this->fails(fn () => $f->payloads->read($f->scope(), $ref), 'payload_unavailable');
            self::assertSame(A\DispatchCertainty::Unknown, $f->replay()->certainty);
        });
    }

    public function testLeaseVersionOwnerAndFenceRejectStaleOrForeignWorkAndSqlRewind(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $claim = $reserved->claim;
            $this->fails(fn () => $f->ledger->resume($claim), 'stale_claim');
            foreach ([
                new A\LedgerClaim($claim->invocationRef, $claim->rowVersion->next(), $claim->leaseOwner, $claim->leaseUntil, $claim->fencingEpoch),
                new A\LedgerClaim($claim->invocationRef, $claim->rowVersion, 'worker:foreign', $claim->leaseUntil, $claim->fencingEpoch),
                new A\LedgerClaim($claim->invocationRef, $claim->rowVersion, $claim->leaseOwner, $claim->leaseUntil, $claim->fencingEpoch->next()),
            ] as $forged) {
                $this->fails(fn () => $f->ledger->markSending($forged, $f->requestBytes()->identity), 'stale_claim');
            }
            $f->expireLease();
            $this->fails(fn () => $f->ledger->markSending($claim, $f->requestBytes()->identity), 'stale_claim');
            $other = $f->makeLedger('worker:b');
            $fresh = (new InvocationRecovery($other, $f->authority))->resume($f->request(), $f->scope());
            self::assertSame('1', $fresh->claim->fencingEpoch->value);
            $sending = $other->markSending($fresh->claim, $f->requestBytes()->identity);
            $unknown = $other->markUnknown($sending->claim);
            $pdo = $f->blobs->catalog->db->pdo;
            foreach ([
                "UPDATE knowkit_answer_invocations SET state='reserved',row_version=row_version+1",
                "UPDATE knowkit_answer_invocations SET original_request_digest=repeat('0',64),row_version=row_version+1",
                'DELETE FROM knowkit_answer_invocations',
            ] as $sql) {
                CatalogFixture::sqlReject($pdo, $sql);
            }
            self::assertSame(A\InvocationState::Unknown, $other->find($unknown->invocationRef)->state);
            self::assertSame(0, $f->transport->sends);
        }, 1);
    }

    public function testExactOutboundIdentityAndManagedPayloadCustodyNeverPersistProtectedPlaintextInSql(): void
    {
        LedgerFixture::run(function (LedgerFixture $f): void {
            $reserved = $f->reserve();
            $request = $f->requestBytes();
            $wrong = Fixture::call(['invocationRef' => $reserved->invocationRef, 'binding' => $f->binding(),
                'completeRequestBytes' => 'changed bytes', 'dependencies' => $request->identity->dependencies]);
            $this->fails(fn () => $f->ledger->markSending($reserved->claim, $wrong), 'outbound_identity_conflict');
            $sending = $f->ledger->markSending($reserved->claim, $request->identity);
            $row = $f->row();
            $ref = new A\InvocationPayloadRef($f->scope()->tenantId, new D\ResourceId($row['model_call_identity_ref']), new D\Sha256($row['model_call_identity_hash']));
            $saved = StrictJson::decode($f->payloads->read($f->scope(), $ref));
            self::assertSame(Jcs::canonicalize($request->identity->toWire()), Jcs::canonicalize($saved));
            self::assertSame(hash('sha256', $request->bytes), $row['request_payload_hash']);
            self::assertSame($sending->modelCallIdentityDigest->value, $row['model_call_identity_hash']);
            $json = json_encode([$row, $f->blobs->catalog->db->execute('SELECT * FROM knowkit_answer_payloads')->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach ([$f->request()->question, 'Synthetic protected response', 'Ранее 漢字', 'Ответ é 🙂'] as $text) {
                self::assertStringNotContainsString($text, $json);
            }
            self::assertSame(0, (int) $f->blobs->catalog->db->execute("SELECT count(*) FROM resource_writers WHERE state<>'closed'")->fetchColumn());
            self::assertNotEmpty($f->payloads->dependencies($ref)->history);
            self::assertEquals($request->identity->dependencies, $f->payloads->dependencies($ref));
        });
    }

    public function testPostIntentRevocationAndEgressDenialCompleteKnownNotSentWithZeroDispatch(): void
    {
        foreach (['source', 'egress'] as $reason) {
            LedgerFixture::run(function (LedgerFixture $f) use ($reason): void {
                $reserved = $f->reserve();
                if ($reason === 'source') {
                    $f->authority->duringSend = fn () => $f->blobs->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
                        [$f->scope()->tenantId->value, BlobFixture::id(3)]);
                } else {
                    $f->authority->egressAllowed = false;
                }
                $outcome = $f->dispatcher()->send($reserved->claim, $f->requestBytes(), Fixture::cancellation());
                self::assertInstanceOf(A\AnswerOutcome::class, $outcome);
                self::assertSame(A\DispatchCertainty::KnownNotSent, $outcome->certainty);
                self::assertNull($outcome->answeredContent());
                self::assertSame(A\InvocationState::Completed, $f->ledger->find($reserved->invocationRef)->state);
                self::assertSame(0, $f->transport->sends);
                self::assertSame(1, $f->authority->sends);
            });
        }
    }

    public function testTransportExceptionOrUnknownResultNeverRetriesOrPublishesPartialBytes(): void
    {
        foreach ([false, true] as $throws) {
            LedgerFixture::run(function (LedgerFixture $f) use ($throws): void {
                $reserved = $f->reserve();
                $f->transport->uncertain = true;
                $f->transport->throws = $throws;
                $outcome = $f->dispatcher()->send($reserved->claim, $f->requestBytes(), Fixture::cancellation());
                self::assertInstanceOf(A\AnswerOutcome::class, $outcome);
                self::assertSame(A\OutcomeCode::TransportUnknown, $outcome->code);
                self::assertSame(A\InvocationState::Unknown, $f->admit()->state);
                $this->fails(fn () => $f->dispatcher()->send($reserved->claim, $f->requestBytes(), Fixture::cancellation()), 'stale_claim');
                self::assertNull($f->replay()->answeredContent());
                self::assertSame(1, $f->transport->sends);
                self::assertSame(0, $f->payloads->responseReads);
            });
        }
    }
}
