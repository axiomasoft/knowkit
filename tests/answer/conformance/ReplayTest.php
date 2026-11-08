<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:conformance')]
final class ReplayTest extends TestCase
{
    public function testCompletedReplayChecksCurrentActorAudienceConsentAndUnknownPolicyBeforePayload(): void
    {
        foreach (['actor_allowed', 'audience_allowed', 'consent_allowed', 'policy_known', 'egress_allowed'] as $right) {
            ReplayFixture::run(function (ReplayFixture $f) use ($right): void {
                $f->completed();
                $row = $f->row();
                $reads = $f->payloads->responseReads;
                $this->mutate($f, 'deny:' . $right);
                $outcome = $f->replay();
                $expected = match ($right) {
                    'policy_known' => A\OutcomeCode::PolicyUnknown,
                    'egress_allowed' => A\OutcomeCode::EgressDenied,
                    default => A\OutcomeCode::Denied,
                };
                self::assertSame($expected, $outcome->code, $right);
                self::assertNull($outcome->answeredContent());
                self::assertTrue($outcome->fixture);
                self::assertSame(A\DispatchCertainty::Sent, $outcome->certainty);
                self::assertSame($reads, $f->payloads->responseReads, 'Denied before response materialization');
                self::assertSame($row, $f->row(), 'Denial must not rewrite completed generation');
                self::assertSame(1, $f->transport->sendCount());
            });
        }
    }

    public function testFullScopeCannotAdoptHistoricalAnswer(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $f->completed();
            $row = $f->row();
            foreach (['tenant', 'kb', 'actor', 'purpose', 'host-scope'] as $change) {
                $s = $f->scope;
                $current = new D\Scope($change === 'tenant' ? new D\TenantId(ReplayFixture::id(90)) : $s->tenantId,
                    $change === 'kb' ? new D\KbId(ReplayFixture::id(90)) : $s->kbId,
                    $change === 'actor' ? new D\ActorId(ReplayFixture::id(90)) : $s->actorId,
                    $change === 'purpose' ? 'different.purpose' : $s->purpose, $s->policyEpoch,
                    $change === 'host-scope' ? new D\Sha256(str_repeat('f', 64)) : $s->hostScopeHash);
                try {
                    $f->replay($current);
                    self::fail('Changed scope adopted old answer: ' . $change);
                } catch (D\DomainFailure $failure) {
                    self::assertSame('scope_mismatch', $failure->errorCode);
                }
            }
            self::assertSame(0, $f->payloads->responseReads);
            self::assertSame($row, $f->row());
        });
    }

    public function testHistoryRevokeBlocksReplayEvenWhenNewestEvidenceIsStillUsable(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $record = $f->completed();
            self::assertCount(1, $record->dependencies->history);
            self::assertSame('history:turn', $record->dependencies->history[0]->ref);
            $row = $f->row();
            $this->mutate($f, 'deny:history_allowed');
            self::assertSame('usable', $f->db->row('SELECT usability FROM source_version_state')['usability']);
            $outcome = $f->replay();
            self::assertSame(A\OutcomeCode::Denied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $f->payloads->responseReads);
            self::assertSame($row, $f->row());
        });
    }

    public function testCorrectionRevokeAndTombstoneNeverRedirectHistoricalCitation(): void
    {
        foreach (['correct', 'revoke', 'tombstone'] as $mutation) {
            ReplayFixture::run(function (ReplayFixture $f) use ($mutation): void {
                $record = $f->completed();
                $citation = $record->dependencies->evidence[0]->canonical();
                $row = $f->row();
                $this->mutate($f, $mutation);
                $outcome = $f->replay();
                self::assertSame(A\OutcomeCode::Denied, $outcome->code, $mutation);
                self::assertNull($outcome->answeredContent());
                self::assertSame(0, $f->payloads->responseReads);
                self::assertSame($citation, $f->ledger->find($record->invocationRef)->dependencies->evidence[0]->canonical());
                self::assertSame($row, $f->row());
                self::assertSame(1, $f->transport->sendCount());
            });
        }
    }

    public function testRevokeDuringBlobReadIsCheckedAgainBeforeReturningPlaintext(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $f->completed();
            $row = $f->row();
            $f->payloads->afterResponseRead = function () use ($f): void {
                $f->payloads->afterResponseRead = null;
                $this->mutate($f, 'revoke');
            };
            $outcome = $f->replay();
            self::assertSame(A\OutcomeCode::Denied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(1, $f->payloads->responseReads);
            self::assertSame($row, $f->row());
        });
    }

    public function testMutableBindingAffectsOnlyNewKeyAndChangedOriginalConflicts(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $first = $f->completed();
            $outcome = $f->replay();
            $originalBinding = $first->pins->binding;
            $wire = $originalBinding->toWire()->with('model', 'new-tutor-model')->with('bindingDigest', str_repeat('e', 64));
            $f->binding = A\ResolvedModelBinding::fromWire($wire);
            $f->failResolution = true;
            self::assertEquals($first, $f->admit());
            self::assertEquals($outcome->toWire(), $f->replay()->toWire());
            self::assertSame(1, $f->resolutions);
            $changed = new A\AnswerRequest($f->scope, 'changed question', $f->request()->priorTurns,
                $f->request()->modelBindingRef, $f->request()->retrievalProfileRef, $f->request()->invocationKey, $f->request()->limits);
            try {
                $f->admit($changed);
                self::fail('Same key with changed original accepted');
            } catch (D\DomainFailure $failure) {
                self::assertSame('invocation_conflict', $failure->errorCode);
            }
            $f->failResolution = false;
            $next = $f->admit($f->request('new-key'));
            self::assertSame('new-tutor-model', $next->pins->binding->model);
            self::assertEquals($originalBinding, $f->ledger->find($first->invocationRef)->pins->binding);
            self::assertSame(2, $f->resolutions);
            self::assertSame(1, $f->transport->sendCount());
        });
    }

    public function testUnavailableHostPolicyReturnsMetadataWithoutLoadingResponse(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $f->completed();
            $row = $f->row();
            $f->authorizationThrows = true;
            $outcome = $f->replay();
            self::assertSame(A\OutcomeCode::PolicyUnknown, $outcome->code);
            self::assertSame(A\DispatchCertainty::Sent, $outcome->certainty);
            self::assertNull($outcome->answeredContent());
            self::assertTrue($outcome->fixture);
            self::assertSame(0, $f->payloads->responseReads);
            self::assertSame($row, $f->row());
        });
    }

    private function mutate(ReplayFixture $f, string $mode): void
    {
        $process = new Process($f, $mode, 'authority:writer');
        self::assertSame('READY', $process->line());
        $process->send();
        self::assertSame('MUTATED', $process->line());
        self::assertSame(0, $process->close());
    }
}
