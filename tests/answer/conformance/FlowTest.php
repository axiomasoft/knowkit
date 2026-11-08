<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Application\AnswerSettlement;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain as D;
use KnowKit\Tests\Answer\Grounding\GroundingFixture;
use KnowKit\Tests\Answer\Ledger\LedgerFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:conformance')]
final class FlowTest extends TestCase
{
    public function testSettlementFailureRetainsCapturedPostgresResponseAndRetriesOnlyBudgetKey(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $f->budget->failCommit = true;
            $record = $f->completed();
            $row = $f->row();
            self::assertCount(1, $f->budget->commits);
            $first = $f->replay();
            $f->budget->failCommit = false;
            self::assertTrue((new AnswerSettlement($f->payloads, $f->budget, $f->clock))->settle($f->scope, $first));
            self::assertCount(2, $f->budget->commits);
            self::assertEquals($f->budget->commits[0], $f->budget->commits[1]);
            self::assertSame('budget:replay:one', $f->budget->commits[1][0]->ref);
            self::assertSame(123, $f->budget->commits[1][1]->inputTokens);
            self::assertSame(17, $f->budget->commits[1][1]->outputTokens);
            self::assertSame($row, $f->row());
            self::assertEquals($first->toWire(), $f->replay()->toWire());
            self::assertSame($record->responsePayloadHash->value, $f->row()['response_payload_hash']);
            self::assertSame(1, $f->transport->sendCount());
            self::assertSame([], $f->budget->releases);
        });
        // Also qualify the public orchestrator's retry path, rather than only its settlement primitive.
        $f = new GroundingFixture();
        $f->settlementFails = true;
        $request = $f->request();
        $service = $f->service();
        $first = $service->answer($request, $f->token());
        $f->settlementFails = false;
        self::assertEquals($first->toWire(), $service->answer($request, $f->token())->toWire());
        self::assertSame(2, $f->commits);
        self::assertSame(1, $f->sends);
        self::assertSame(1, $f->resolutions);
    }

    public function testCancellationIsKnownUnsentKnownSentOrUnknownWithoutRefundingPossibleSpend(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $reserved = $f->reserve($f->admit());
            $outcome = $f->dispatch($reserved, cancelled: true);
            self::assertInstanceOf(A\AnswerOutcome::class, $outcome);
            self::assertSame(A\OutcomeCode::Cancelled, $outcome->code);
            self::assertFalse($outcome->sent);
            self::assertTrue($outcome->fixture);
            self::assertSame(A\DispatchCertainty::KnownNotSent, $outcome->certainty);
            self::assertSame(0, $f->transport->sendCount());
            self::assertSame(0, $f->transport->outboundBytes());
            self::assertSame('completed', $f->row()['state']);
        });
        foreach (['sent', 'unknown', 'partial'] as $certainty) {
            $f = new GroundingFixture();
            $f->unknownTransport = $certainty === 'unknown';
            $f->incomplete = $certainty === 'partial';
            $f->onSend = static function () use ($f): void {
                $f->cancelled = true;
            };
            $service = $f->service();
            $request = $f->request();
            $outcome = $service->answer($request, $f->token());
            self::assertSame($certainty === 'sent' ? A\OutcomeCode::Cancelled : A\OutcomeCode::TransportUnknown, $outcome->code);
            self::assertSame($certainty === 'sent' ? true : null, $outcome->sent);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->releases);
            self::assertSame($certainty === 'sent' ? 1 : 0, $f->commits);
            self::assertSame($outcome->code, $service->answer($request, $f->token())->code);
            self::assertSame(1, $f->sends);
        }
    }

    public function testUnknownDispatchRemainsNonSendableWithNoBudgetRelease(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $record = $f->reserve($f->admit());
            $outcome = $f->dispatch($record);
            self::assertInstanceOf(A\AnswerOutcome::class, $outcome);
            self::assertSame(A\OutcomeCode::TransportUnknown, $outcome->code);
            self::assertTrue($outcome->fixture);
            $row = $f->row();
            self::assertSame('unknown', $row['state']);
            self::assertNull($row['response_payload_ref']);
            $f->failResolution = true;
            self::assertSame(A\OutcomeCode::TransportUnknown, $f->replay()->code);
            self::assertSame(A\OutcomeCode::TransportUnknown, $f->replay()->code);
            self::assertSame(1, $f->transport->sendCount());
            self::assertSame($row, $f->row());
            self::assertSame([], $f->budget->releases);
            self::assertSame([], $f->budget->commits);
        }, unknown: true);
    }

    public function testScriptedTransportIsFixtureOnlyAndNeverAProductionFallback(): void
    {
        $descriptor = ScriptedTransport::testDescriptor();
        self::assertFalse($descriptor->production);
        ReplayFixture::run(function (ReplayFixture $f): void {
            $f->completed();
            self::assertTrue($f->replay()->fixture);
            self::assertSame(1, $f->transport->sendCount());
        });
        try {
            new EffectiveProfile(ProfileMode::Production, $descriptor);
            self::fail('Fixture admitted in production');
        } catch (D\DomainFailure $failure) {
            self::assertSame('test_transport_forbidden', $failure->errorCode);
        }
        $f = new GroundingFixture();
        $f->testTransport = true;
        $this->expectException(D\DomainFailure::class);
        $f->service(mode: ProfileMode::Production);
    }

    public function testSecondGenericProjectAndSchoolBKeepSameLocalKeyTenantIsolated(): void
    {
        ReplayFixture::run(function (ReplayFixture $schoolA, LedgerFixture $base): void {
            $first = $schoolA->completed();
            foreach ([2 => 'lesson.read', 3 => 'support.read'] as $tenant => $purpose) {
                $c = $base->blobs->catalog;
                $c->tenant($tenant);
                $c->kb($tenant);
                $c->source(2, $tenant);
                $c->source(3, $tenant);
                $s = $c->scope($tenant);
                $scope = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $purpose, $s->policyEpoch,
                    new D\Sha256(hash('sha256', 'current-project-' . $tenant)));
                $other = new ReplayFixture($schoolA->db, $schoolA->blobRoot, $scope, 'project:' . $tenant);
                $other->seedAuthority();
                $record = $other->completed();
                self::assertSame($first->invocationRef->key->value, $record->invocationRef->key->value);
                self::assertNotEquals($first->invocationRef->tenantId, $record->invocationRef->tenantId);
                self::assertNotEquals($first->responsePayloadRef, $record->responsePayloadRef);
                self::assertNotEquals($first->requestFingerprint, $record->requestFingerprint);
                self::assertSame(A\OutcomeCode::Answered, $other->replay()->code);
                try {
                    $other->ledger->find($first->invocationRef);
                    self::fail('Cross-tenant ledger lookup admitted');
                } catch (D\DomainFailure $failure) {
                    self::assertSame('scope_mismatch', $failure->errorCode);
                }
                $other->deny('audience_allowed');
                self::assertSame(A\OutcomeCode::Denied, $other->replay()->code);
                self::assertSame(A\OutcomeCode::Answered, $schoolA->replay()->code);
                self::assertSame(1, $other->transport->sendCount());
            }
        });
    }
}
