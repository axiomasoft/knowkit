<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Grounding;

use KnowKit\Answer\Adapter\StaticCeilingBudget;
use KnowKit\Answer\Application\GroundingEvidenceReader;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Answer\Contracts\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:grounding')]
final class SafetyTest extends TestCase
{
    public function testFreshDisclosureDeniedUnknownOrUnavailableNeverReachesModelOrBudget(): void
    {
        foreach ([D\Outcome::Denied, D\Outcome::Unknown, D\Outcome::Unavailable] as $failure) {
            $f = new GroundingFixture();
            $f->disclosureFailure = $failure;
            $outcome = $f->service()->answer($f->request(), $f->token());
            self::assertSame(match ($failure) {
                D\Outcome::Denied => A\OutcomeCode::Denied,
                D\Outcome::Unknown => A\OutcomeCode::PolicyUnknown,
                default => A\OutcomeCode::Unavailable,
            }, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->sends);
            self::assertNotContains('budget-reserve', $f->events);
            self::assertNotContains('egress', $f->events);
        }
    }

    public function testHistoryNeedsCurrentFullScopeAttestationAtBothGuards(): void
    {
        foreach (['absent', 'expired', 'scope', 'digest', 'expires-after-mark'] as $case) {
            $f = new GroundingFixture();
            $turns = $f->request()->priorTurns;
            $scope = $case === 'scope' ? Fixture::scope(['hostScopeHash' => str_repeat('0', 64)]) : Fixture::scope();
            $digest = $case === 'digest' ? new D\Sha256(str_repeat('0', 64)) : $turns->digest();
            $attestation = $case === 'absent' ? null : new A\HistoryReadAttestation($scope, $digest, 'history:current',
                $f->date()->modify($case === 'expired' ? '-1 second' : '+1 second'));
            $request = $f->request(['priorTurns' => new A\BoundedTurns($turns->turns, $attestation)]);
            if ($case === 'expires-after-mark') {
                $f->afterMark = static function () use ($f): void {
                    $f->seconds = 2;
                };
            }
            $outcome = $f->service()->answer($request, $f->token());
            self::assertSame(A\OutcomeCode::Denied, $outcome->code, $case);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->sends);
            self::assertSame(0, $f->outboundBytes);
            self::assertSame($case === 'expires-after-mark' ? 1 : 0, $f->releases);
        }
    }

    public function testReplayUsesOriginalPinsAndRetriesSettlementByKeyWithoutRegeneration(): void
    {
        $f = new GroundingFixture();
        $f->settlementFails = true;
        $request = $f->request();
        $service = $f->service();
        $first = $service->answer($request, $f->token());
        self::assertSame(A\OutcomeCode::Answered, $first->code);
        self::assertSame(1, $f->commits);
        $f->settlementFails = false;
        $f->binding = Fixture::binding(['model' => 'changed-tutor-model']);
        $second = $service->answer($request, $f->token());
        self::assertEquals($first->toWire(), $second->toWire());
        self::assertSame(2, $f->commits);
        self::assertSame(1, $f->resolutions);
        self::assertSame(1, $f->sends);
        self::assertSame(1, count(array_filter($f->events, static fn (string $event) => $event === 'retrieve')));
        $f->replayDenied = true;
        $denied = $service->answer($request, $f->token());
        self::assertSame(A\OutcomeCode::Denied, $denied->code);
        self::assertNull($denied->answeredContent());
        self::assertSame(A\OutcomeCode::Answered, $f->ledger->outcome->code);
        self::assertSame(1, $f->sends);
    }

    public function testUnknownTransportPersistsActualNullableUsageAndNeverRefundsOrResends(): void
    {
        foreach (['known-usage', 'null-usage', 'exception'] as $case) {
            $f = new GroundingFixture();
            $f->unknownTransport = true;
            $f->noUsage = $case === 'null-usage';
            $f->transportThrows = $case === 'exception';
            $service = $f->service();
            $request = $f->request();
            $outcome = $service->answer($request, $f->token());
            self::assertSame(A\OutcomeCode::TransportUnknown, $outcome->code);
            self::assertNotNull($outcome->usageRef);
            [$id] = explode(':', $outcome->usageRef, 2);
            $observation = StrictJson::decode($f->stored[$id][0]);
            self::assertSame($case === 'known-usage' ? 123 : null, $observation->get('inputTokens'));
            self::assertSame($case === 'known-usage' ? 17 : null, $observation->get('outputTokens'));
            self::assertSame($f->lastRequest->identity->digest()->value, $observation->get('modelCallIdentityDigest'));
            self::assertSame(A\OutcomeCode::TransportUnknown, $service->answer($request, $f->token())->code);
            self::assertSame(1, $f->sends);
            self::assertSame(0, $f->commits);
            self::assertSame(0, $f->releases);
        }
    }

    public function testKnownCompletionWithUnknownUsageSettlesNullObservations(): void
    {
        $f = new GroundingFixture();
        $f->noUsage = true;
        $outcome = $f->service()->answer($f->request(), $f->token());
        self::assertSame(A\OutcomeCode::Answered, $outcome->code);
        self::assertNotNull($outcome->usageRef);
        self::assertNull($f->settled->inputTokens);
        self::assertNull($f->settled->outputTokens);
        self::assertSame(0, $f->releases);
    }

    public function testRevocationAfterProviderReplyPreventsPlaintextPublication(): void
    {
        $f = new GroundingFixture();
        $f->onSend = static function () use ($f): void {
            $f->replayDenied = true;
        };
        $outcome = $f->service()->answer($f->request(), $f->token());
        self::assertSame(A\OutcomeCode::Denied, $outcome->code);
        self::assertSame(A\DispatchCertainty::Sent, $outcome->certainty);
        self::assertNull($outcome->answeredContent());
        self::assertSame(1, $f->sends);
        self::assertSame(17, $f->settled->outputTokens);
        self::assertSame(A\OutcomeCode::Answered, $f->ledger->outcome->code);
    }

    public function testUnknownEgressAndExpiredPreSendDecisionFailClosed(): void
    {
        foreach (['unknown', 'expired-after-mark'] as $case) {
            $f = new GroundingFixture();
            if ($case === 'unknown') {
                $f->egressDecision = 'Unknown';
            }
            if ($case === 'expired-after-mark') {
                $f->afterMark = static function () use ($f): void {
                    $f->seconds = 6;
                };
            }
            $outcome = $f->service()->answer($f->request(), $f->token());
            self::assertSame($case === 'unknown' ? A\OutcomeCode::PolicyUnknown : A\OutcomeCode::Denied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->sends);
            self::assertSame(0, $f->outboundBytes);
        }
    }

    public function testByteBudgetsRemainActiveWithoutTokenizer(): void
    {
        foreach ([new A\AnswerLimits(inputBytes: 64), new A\AnswerLimits(evidenceBytes: 8)] as $limits) {
            $f = new GroundingFixture();
            $outcome = $f->service()->answer($f->request(['limits' => $limits]), $f->token());
            self::assertSame(A\OutcomeCode::Unavailable, $outcome->code);
            self::assertSame(0, $f->sends);
            self::assertNotContains('budget-reserve', $f->events);
        }
    }

    public function testStaticCeilingsRejectOverflowAndUnknownTokenEstimateWithStableTenantKey(): void
    {
        $scope = Fixture::scope();
        $key = new A\InvocationKey('same-key');
        $budget = new StaticCeilingBudget(100, 50, 20, 100);
        $estimate = new A\Estimate($scope, 100, 50, 20, 100);
        $reservation = $budget->reserve($key, $estimate);
        self::assertSame(A\ReservationStatus::Confirmed, $reservation->status);
        self::assertEquals($reservation, $budget->reserve($key, $estimate));
        $budget->commit($reservation, new A\UsageObservation());
        foreach ([new A\Estimate($scope, 101, 50, 20, 100), new A\Estimate($scope, 100, null, 20, 100),
            new A\Estimate($scope, 100, 51, 20, 100), new A\Estimate($scope, 100, 50, 21, 100), new A\Estimate($scope, 100, 50, 20, 101)] as $overflow) {
            self::assertSame(A\ReservationStatus::Denied, $budget->reserve($key, $overflow)->status);
        }
        $other = Fixture::scope(['tenantId' => GroundingFixture::id(99)]);
        self::assertNotSame($reservation->ref, $budget->reserve($key, new A\Estimate($other, 100, 50, 20, 100))->ref);
        $this->expectException(\InvalidArgumentException::class);
        $budget->release(new A\BudgetReservation($reservation->invocationRef, 'unknown', A\ReservationStatus::Unknown), A\ReleaseReason::FailedBeforeSend);
    }

    public function testEvidenceRequiresCommittedUsageAndMatchingFullScope(): void
    {
        foreach (['no-usage', 'actor', 'host-scope', 'tenant'] as $case) {
            $f = new GroundingFixture();
            $set = $f->evidence(GroundingFixture::id(20));
            $scope = match ($case) {
                'actor' => Fixture::scope(['actorId' => GroundingFixture::id(99)]),
                'host-scope' => Fixture::scope(['hostScopeHash' => str_repeat('0', 64)]),
                'tenant' => Fixture::scope(['tenantId' => GroundingFixture::id(99)]),
                default => Fixture::scope(),
            };
            if ($case === 'no-usage') {
                $set = new D\EvidenceSet($set->toWire(), $f->validator);
            }
            try {
                new A\GroundingEvidence($set, $f->selection(), $scope, $f->validator);
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Denied, $failure->outcome);

                continue;
            }
            self::fail("Historical/mismatched evidence accepted: {$case}");
        }
    }

    public function testExactProfileMismatchCannotRetrieve(): void
    {
        $f = new GroundingFixture();
        $reader = new GroundingEvidenceReader($f, $f, $f, $f->validator, new A\ProfileRef('changed', '1', new D\Sha256(str_repeat('0', 64))));
        try {
            $reader->read($f->request());
        } catch (D\DomainFailure $failure) {
            self::assertSame('retrieval_profile_unavailable', $failure->errorCode);
            self::assertNotContains('retrieve', $f->events);

            return;
        }
        self::fail('Mutable/unconfigured profile was used');
    }

    public function testObservationOrResponsePersistenceFailureNeverPublishesOrRefunds(): void
    {
        foreach (['observation', 'response'] as $kind) {
            $f = new GroundingFixture();
            $f->failPayloadKind = $kind;
            $request = $f->request();
            $service = $f->service();
            $outcome = $service->answer($request, $f->token());
            self::assertSame(A\OutcomeCode::TransportUnknown, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->commits);
            self::assertSame(0, $f->releases);
            self::assertSame(A\OutcomeCode::TransportUnknown, $service->answer($request, $f->token())->code);
            self::assertSame(1, $f->sends);
        }
    }

    public function testProductionCompositionRejectsScriptedTransportBeforeAnyWork(): void
    {
        $f = new GroundingFixture();
        $f->testTransport = true;
        $this->expectException(D\DomainFailure::class);
        $this->expectExceptionMessage('test_transport_forbidden');
        $f->service(mode: ProfileMode::Production);
    }

    public function testMalformedOutputAndPartialOrWrongModelNeverBecomeAnswered(): void
    {
        foreach (['malformed', 'extra-fields', 'no-citations', 'duplicate-citations', 'partial', 'wrong-model', 'byte-bound'] as $case) {
            $f = new GroundingFixture();
            $citation = json_decode($f->citation()->canonical(), true, 32, JSON_THROW_ON_ERROR);
            $output = ['text' => 'synthetic answer', 'citations' => [$citation]];
            if ($case === 'extra-fields') {
                $output['instructions'] = 'do something else';
            }
            if ($case === 'no-citations') {
                $output['citations'] = [];
            }
            if ($case === 'duplicate-citations') {
                $output['citations'][] = $citation;
            }
            $f->outputText = $case === 'malformed' ? '{' : json_encode($output, JSON_THROW_ON_ERROR);
            $f->incomplete = $case === 'partial';
            $f->observedModel = $case === 'wrong-model' ? 'different-model' : null;
            $request = $f->request($case === 'byte-bound' ? ['limits' => new A\AnswerLimits(outputBytes: 128)] : []);
            $result = $f->service()->answer($request, $f->token());
            $uncertain = in_array($case, ['partial', 'wrong-model'], true);
            self::assertSame($uncertain ? A\OutcomeCode::TransportUnknown : A\OutcomeCode::Refused, $result->code, $case);
            self::assertNull($result->answeredContent());
            self::assertSame(1, $f->sends);
            self::assertSame(0, $f->releases);
            self::assertSame($uncertain ? 0 : 1, $f->commits);
            if ($case === 'wrong-model') {
                [$id] = explode(':', $result->usageRef, 2);
                $observation = StrictJson::decode($f->stored[$id][0]);
                self::assertTrue($observation->get('complete'));
                self::assertSame('sent', $observation->get('certainty'));
                self::assertSame('different-model', $observation->get('observedModelRef'));
            }
        }
    }

    public function testCanonicalSpanLengthSnapshotAndSelectedRevisionCannotBeSubstituted(): void
    {
        foreach (['span', 'snapshot', 'revision'] as $case) {
            $f = new GroundingFixture();
            try {
                if ($case === 'span') {
                    $wire = $f->citation()->toWire();
                    $citation = new D\CitationRef($wire->with('spans', [$wire->get('spans')[0]->with('byteEnd', 1)]), $f->validator);
                    new A\GroundingFragment($citation, GroundingFixture::corpus()['canonical'], null, 'verbatim');
                }
                if ($case === 'snapshot') {
                    $f->mutateEvidence = static fn (JsonObject $wire) => $wire->with('snapshotRef', $wire->get('snapshotRef')->with('snapshotId', GroundingFixture::id(99)));
                    new A\GroundingEvidence($f->evidence(GroundingFixture::id(20)), $f->selection(), Fixture::scope(), $f->validator);
                }
                if ($case === 'revision') {
                    $wire = $f->selection()->toWire()->with('candidateRefs', [$f->chunk()->with('documentRevisionId', GroundingFixture::id(99))]);
                    new A\GroundingEvidence($f->evidence(GroundingFixture::id(20)), new D\Selection($wire, $f->validator), Fixture::scope(), $f->validator);
                }
            } catch (D\DomainFailure $failure) {
                self::assertContains($failure->errorCode, ['canonical_evidence_invalid', 'scope_mismatch', 'evidence_selection_mismatch']);

                continue;
            }
            self::fail("Substituted {$case} was accepted");
        }
    }
}
