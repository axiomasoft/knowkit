<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Grounding;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Tests\Answer\Contracts\ExactTokenizer;
use KnowKit\Tests\Answer\Contracts\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:grounding')]
final class GroundingTest extends TestCase
{
    public function testCanonicalTextIsInExactAdmittedRequestBeforeSingleSend(): void
    {
        $f = new GroundingFixture();
        $request = $f->request();
        $outcome = $f->service()->answer($request, $f->token());
        self::assertSame(A\OutcomeCode::Answered, $outcome->code);
        self::assertTrue($outcome->fixture);
        self::assertSame(GroundingFixture::corpus()['answer'], $outcome->answeredContent()->text);
        self::assertSame(1, $f->sends);
        self::assertGreaterThan(0, $f->outboundBytes);
        $body = json_decode($f->lastRequest->bytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertContains(GroundingFixture::corpus()['canonical'], array_column($body['messages'], 'content'));
        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame(1, count(array_filter($body['messages'], static fn (array $message) => $message['role'] === 'system')));
        self::assertSame($request->question, $body['messages'][array_key_last($body['messages'])]['content']);
        self::assertSame(hash('sha256', $f->lastRequest->bytes), $f->lastRequest->identity->requestBytesDigest->value);
        self::assertEquals($f->ledger->record->modelCallIdentityDigest, $f->lastRequest->identity->digest());
        self::assertSame(strlen($f->lastRequest->bytes), $f->estimate->inputBytes);
        self::assertSame($f->citation()->canonical(), $outcome->answeredContent()->citations[0]->canonical());
        self::assertSame(123, $f->settled->inputTokens);
        self::assertSame(17, $f->settled->outputTokens);
        self::assertSame(0, $f->releases);
        [$receiptId] = explode(':', $f->lastRequest->identity->egressDecision->ref, 2);
        $initialCheck = json_decode($f->stored[$receiptId][0], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $f->lastRequest->bytes), $initialCheck['requestBytesDigest']);
        self::assertSame('current:1', $initialCheck['receipt']['ownerRevision']);
        self::assertSame(HashDomain::PolicyBinding->digest($f->policyBinding()), $f->lastRequest->identity->egressDecision->policyVersion);
        self::assertSame($initialCheck['receipt']['checkedAt'], $f->lastRequest->identity->egressDecision->checkedAt->format('Y-m-d\TH:i:s.u\Z'));
        [$observationId] = explode(':', $outcome->usageRef, 2);
        $observation = json_decode($f->stored[$observationId][0], true, 32, JSON_THROW_ON_ERROR);
        [$finalId] = explode(':', $observation['finalEgressCheck']['ref'], 2);
        $finalCheck = json_decode($f->stored[$finalId][0], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($f->lastRequest->identity->digest()->value, $finalCheck['modelCallIdentityDigest']);
        self::assertSame($f->lastRequest->identity->requestBytesDigest->value, $finalCheck['requestBytesDigest']);
        $this->ordered($f->events, ['find', 'resolve', 'begin', 'retrieve', 'disclose', 'authorize', 'egress', 'budget-reserve',
            'ledger-reserve', 'markSending', 'authorize', 'egress', 'live', 'send', 'persist:observation', 'complete', 'budget-commit', 'replay-authorize']);
        $this->expectException(\InvalidArgumentException::class);
        new A\TransportRequest($f->lastRequest->identity, $f->lastRequest->bytes . ' ');
    }

    public function testHistoryEgressDenialSendsZeroBodyBytes(): void
    {
        $this->egressDenies('HISTORY');
    }

    public function testQuestionEgressDenialSendsZeroBodyBytes(): void
    {
        $this->egressDenies('QUESTION');
    }

    public function testRevokeAfterMarkSendingPreventsDispatchAndReleasesConfirmedUnusedBudget(): void
    {
        $f = new GroundingFixture();
        $f->afterMark = static function () use ($f): void {
            $f->revoked = true;
        };
        $outcome = $f->service()->answer($f->request(), $f->token());
        self::assertSame(A\OutcomeCode::Denied, $outcome->code);
        self::assertSame(A\DenialReason::Revoked, $outcome->reason);
        self::assertSame(A\DispatchCertainty::KnownNotSent, $outcome->certainty);
        self::assertSame(A\InvocationState::Completed, $f->ledger->record->state);
        self::assertSame(0, $f->sends);
        self::assertSame(0, $f->outboundBytes);
        self::assertSame(1, $f->releases);
        self::assertNull($outcome->answeredContent());
    }

    public function testInvalidCitationRefusesWithActualUsageAndNoRepair(): void
    {
        foreach (['citationId', 'tenantId', 'sourceId', 'sourceVersionId', 'documentRevisionId', 'documentId', 'sourceDigest', 'irDigest', 'contentDigest', 'spans', 'origin'] as $field) {
            $f = new GroundingFixture();
            $f->mutateCitation = static function (JsonObject $wire) use ($field): JsonObject {
                $value = match ($field) {
                    'spans' => [$wire->get('spans')[0]->with('byteEnd', 1)],
                    'origin' => 'generated',
                    'sourceDigest', 'irDigest', 'contentDigest' => str_repeat('0', 64),
                    default => GroundingFixture::id(99),
                };

                return $wire->with($field, $value);
            };
            $result = $f->service()->answer($f->request(), $f->token());
            self::assertSame(A\OutcomeCode::Refused, $result->code, $field);
            self::assertSame(A\RefusalReason::InvalidCitation, $result->reason, $field);
            self::assertNull($result->answeredContent());
            self::assertSame(1, $f->sends);
            self::assertSame(1, $f->commits);
            self::assertSame(17, $f->settled->outputTokens);
            self::assertSame(0, $f->releases);
            self::assertNotNull($result->usageRef);
        }
    }

    public function testPromptInjectionStaysUntrustedDataAndCannotSetSystemPolicy(): void
    {
        $f = new GroundingFixture();
        $attack = GroundingFixture::corpus()['injection'];
        $f->evidenceText = $attack;
        $turns = new A\BoundedTurns([new A\PriorTurn(A\MessageRole::Assistant, $attack)]);
        $attestation = new A\HistoryReadAttestation(Fixture::scope(), $turns->digest(), 'history:attack', $f->date()->modify('+60 seconds'));
        $request = $f->request(['question' => $attack, 'priorTurns' => new A\BoundedTurns($turns->turns, $attestation)]);
        $outcome = $f->service()->answer($request, $f->token());
        self::assertSame(A\OutcomeCode::Answered, $outcome->code);
        $body = json_decode($f->lastRequest->bytes, true, 32, JSON_THROW_ON_ERROR);
        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'system') {
                self::assertStringNotContainsString($attack, $message['content']);
                self::assertStringContainsString('untrusted data', $message['content']);

                continue;
            }
            if ($message['content'] === $attack) {
                self::assertContains($message['role'], ['user', 'assistant']);
            }
        }
        self::assertSame('1', $f->lastRequest->identity->template->revision);
        $this->expectException(\InvalidArgumentException::class);
        new A\PriorTurn(A\MessageRole::System, $attack);
    }

    public function testUnknownOrDeniedBudgetNeverSendsOrRefunds(): void
    {
        foreach (['unknown', 'denied', 'lost-ack'] as $case) {
            $f = new GroundingFixture();
            $f->reservationStatus = $case === 'denied' ? A\ReservationStatus::Denied : A\ReservationStatus::Unknown;
            $f->reserveThrows = $case === 'lost-ack';
            $result = $f->service()->answer($f->request(), $f->token());
            self::assertSame(A\OutcomeCode::BudgetDenied, $result->code);
            self::assertSame(0, $f->sends);
            self::assertSame(0, $f->releases);
            self::assertSame(0, $f->commits);
            self::assertNotContains('markSending', $f->events);
        }
    }

    public function testCancellationBeforeAndAfterSendPreservesDispatchAndNullableUsage(): void
    {
        foreach (['before-mark', 'after-mark', 'after-send', 'ambiguous'] as $case) {
            $f = new GroundingFixture();
            $cancel = static function () use ($f): void {
                $f->cancelled = true;
            };
            match ($case) {
                'before-mark' => $f->afterReserve = $cancel,
                'after-mark' => $f->afterMark = $cancel,
                default => $f->onSend = $cancel,
            };
            $f->unknownTransport = $case === 'ambiguous';
            $f->noUsage = $case === 'ambiguous';
            $result = $f->service()->answer($f->request(), $f->token());
            self::assertSame($case === 'ambiguous' ? A\OutcomeCode::TransportUnknown : A\OutcomeCode::Cancelled, $result->code);
            self::assertNull($result->answeredContent());
            $sent = in_array($case, ['after-send', 'ambiguous'], true);
            self::assertSame($sent ? 1 : 0, $f->sends);
            self::assertSame($sent ? 0 : 1, $f->releases);
            if ($case === 'after-send') {
                self::assertTrue($result->sent);
                self::assertSame(17, $f->settled->outputTokens);
            }
            if ($case === 'ambiguous') {
                self::assertSame(A\DispatchCertainty::Unknown, $result->certainty);
                self::assertSame(0, $f->commits);
                [$id] = explode(':', $result->usageRef, 2);
                $observation = json_decode($f->stored[$id][0], true, 32, JSON_THROW_ON_ERROR);
                self::assertNull($observation['inputTokens']);
                self::assertNull($observation['outputTokens']);
            }
        }
    }

    public function testExplicitTokenBudgetRequiresExactPinnedTokenizerAndNoByteApproximation(): void
    {
        foreach (['absent', 'wrong-vocabulary', 'unconfirmed', 'exact'] as $case) {
            $f = new GroundingFixture();
            $identity = ExactTokenizer::identity();
            if ($case !== 'absent') {
                $pinned = $case === 'wrong-vocabulary' ? new D\TokenizerIdentity($identity->id, $identity->version, new D\Sha256(str_repeat('9', 64))) : $identity;
                $capabilities = new A\ModelCapabilities($f->binding->capabilities->digest, $f->binding->capabilities->expiresAt, true, null, null, $pinned);
                $f->binding = Fixture::binding(['transport' => $f->descriptor(), 'capabilities' => $capabilities]);
            }
            $tokenizer = $case === 'absent' ? null : new ExactTokenizer($case !== 'unconfirmed');
            try {
                $result = $f->service($tokenizer)->answer($f->request(['limits' => new A\AnswerLimits(inputTokens: 20000)]), $f->token());
                self::assertSame('exact', $case);
                self::assertSame(A\OutcomeCode::Answered, $result->code);
            } catch (D\DomainFailure $failure) {
                self::assertNotSame('exact', $case);
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                self::assertContains($failure->errorCode, ['tokenizer_required', 'tokenizer_mismatch', 'unsupported_capability']);
            }
            self::assertSame($case === 'exact' ? 1 : 0, $f->sends, $case);
            if ($case === 'exact') {
                self::assertSame(mb_strlen($f->lastRequest->bytes), $f->estimate->inputTokens);
            }
        }
    }

    public function testNoEvidenceNeverReservesOrSends(): void
    {
        $f = new GroundingFixture();
        $f->empty = true;
        $outcome = $f->service()->answer($f->request(), $f->token());
        self::assertSame(A\OutcomeCode::NoEvidence, $outcome->code);
        self::assertSame(A\DispatchCertainty::KnownNotSent, $outcome->certainty);
        self::assertSame(0, $f->sends);
        self::assertNotContains('disclose', $f->events);
        self::assertNotContains('budget-reserve', $f->events);
    }

    private function egressDenies(string $category): void
    {
        foreach (['before-reserve', 'after-mark'] as $stage) {
            $f = new GroundingFixture();
            if ($stage === 'before-reserve') {
                $f->egressDeny = $category;
            }
            if ($stage === 'after-mark') {
                $f->afterMark = static function () use ($f, $category): void {
                    $f->egressDeny = $category;
                };
            }
            $outcome = $f->service()->answer($f->request(), $f->token());
            self::assertSame(A\OutcomeCode::EgressDenied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->sends);
            self::assertSame(0, $f->outboundBytes);
            self::assertSame($stage === 'after-mark' ? 1 : 0, $f->releases);
        }
    }

    private function ordered(array $actual, array $expected): void
    {
        $position = 0;
        foreach ($actual as $event) {
            if (($expected[$position] ?? null) === $event) {
                $position++;
            }
        }
        self::assertSame(count($expected), $position, Jcs::canonicalize($actual));
    }
}
