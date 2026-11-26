<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Handler;

use Chatom\Authorization\Contracts\ChannelAccess;
use Chatom\Authorization\Data\AuthorizedChannel;
use Chatom\Authorization\Data\LockedChannel;
use Chatom\Channels\Data\ChannelSnapshot;
use Chatom\Messages\Enums\MessageHistoryOrder;
use Chatom\Operations\Pipeline\OperationContext;
use Chatom\Operations\Pipeline\Phase;
use KnowKit\Answer\Domain as A;
use KnowKit\Chatom\ChatomReplyBoundary;
use KnowKit\Chatom\ChatomReplyMapper;
use KnowKit\Domain\DomainFailure;
use KnowKit\Tests\Chatom\Contracts\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/NativeReply.php';

#[Group('chatom:handler')]
final class HandlerTest extends TestCase
{
    public function testAuthorizedHistoryUsesCurrentViewerFullScopeAndNativeKey(): void
    {
        $f = new HandlerFixture();
        $reply = $this->reply($f);
        $handler = $f->handler($reply->boundary);
        $handler->handle(invocation: $f->invocation(), reply: $reply);
        self::assertFalse($handler->streaming());
        self::assertSame(1, $f->answer->sends);
        self::assertCount(1, $reply->saved);
        self::assertSame('final.v1', array_key_first($reply->saved));
        self::assertSame(HandlerFixture::corpus()['invocationKey'], $f->answer->ledger->record->invocationRef->key->value);
        self::assertEquals($f->binding->hostScopeInputs, $f->answer->lastRequest->identity->binding->scope);
        self::assertSame(MessageHistoryOrder::PositionDescending, $f->lastQuery->order);
        self::assertSame('user:learner', (string) $f->lastQuery->viewer);
        self::assertSame(['user', 'assistant'], array_column(array_map(static fn (A\PriorTurn $turn): array => ['role' => $turn->role->value], $f->lastHistory->priorTurns->turns), 'role'));
        $bytes = $f->answer->lastRequest->bytes;
        self::assertStringContainsString(HandlerFixture::corpus()['history'], $bytes);
        self::assertStringContainsString(HandlerFixture::corpus()['question'], $bytes);
        self::assertStringNotContainsString('UNAUTHORIZED RAW SNAPSHOT', $bytes);
        self::assertStringContainsString('knowkit:citation:', json_encode($reply->saved));
        self::assertSame($f->lastHistory->manifestRef(), $f->answer->ledger->record->dependencies->history[0]->ref);
        self::assertSame($f->answer->ledger->record->dependencies, $f->lastDependencies);
    }

    public function testRevokedUnknownAndChangedHistoryDenyWithoutProtectedEffects(): void
    {
        foreach (['revoked', 'unknown', 'changed', 'missing-trigger', 'wrong-order'] as $case) {
            $f = new HandlerFixture();
            $reply = $this->reply($f);
            if ($case === 'missing-trigger') {
                array_shift($f->messages);
            } elseif ($case === 'wrong-order') {
                $f->messages = array_reverse($f->messages);
            } else {
                $f->afterRead = static function () use ($f, $case): void {
                    if ($case === 'changed') {
                        $f->messages[1] = $f->message(id: 'prior', position: 99, text: 'revoked source', revision: 2);
                        return;
                    }
                    $f->denied[] = 'history:' . $case;
                };
            }
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::assertSame(0, $f->answer->sends, $case);
            self::assertSame(0, $f->answer->outboundBytes, $case);
            self::assertNull($f->answer->ledger->record, $case);
            self::assertSame([], $reply->proposals, $case);
        }
    }

    public function testBotAllowedCannotSubstituteForLearnerAudienceClassLessonConsentRightsOrSource(): void
    {
        foreach (['learner', 'audience', 'class', 'lesson', 'consent', 'rights', 'source', 'unknown', 'bot', 'assistant', 'channel'] as $dimension) {
            $f = new HandlerFixture();
            $reply = $this->reply($f);
            $f->denied[] = $dimension;
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::assertSame(0, $f->reads);
            self::assertSame(0, $f->answer->sends);
            self::assertSame(0, $f->answer->outboundBytes);
            self::assertNull($f->answer->ledger->record);
            self::assertSame([], $reply->saved);
        }
    }

    public function testRevocationAndBindingChangesAtModelAndReplyBoundariesDeny(): void
    {
        foreach (['after-reserve', 'after-mark', 'after-model', 'native-mutation', 'native-owner-io', 'binding-before-model', 'binding-before-reply'] as $point) {
            $f = new HandlerFixture();
            $native = $this->createMock(ChannelAccess::class);
            $authorized = $this->nativeValue(AuthorizedChannel::class, []);
            $native->method('reauthorize')->willReturnCallback(static function () use ($f, $point, $authorized): AuthorizedChannel {
                if ($point === 'native-owner-io') {
                    $f->denied[] = 'consent';
                }
                return $authorized;
            });
            $reply = $this->reply(f: $f, native: $native);
            $deny = static function () use ($f): void {
                $f->denied[] = 'source';
            };
            $change = static fn () => $f->changeBinding();
            match ($point) {
                'after-reserve' => $f->answer->afterReserve = $deny,
                'after-mark' => $f->answer->afterMark = $deny,
                'binding-before-model' => $f->answer->afterMark = $change,
                'after-model' => $f->answer->onSend = $deny,
                'binding-before-reply' => $f->answer->onSend = $change,
                'native-mutation' => $reply->beforeMutation = $deny,
                default => null,
            };
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            $expectedSends = in_array($point, ['after-reserve', 'after-mark', 'binding-before-model'], true) ? 0 : 1;
            self::assertSame($expectedSends, $f->answer->sends, $point);
            self::assertSame([], $reply->saved, $point);
            if ($expectedSends === 0) {
                self::assertSame(0, $f->answer->outboundBytes, $point);
            }
        }
    }

    public function testLostAckReplaysSameSavedSlotBodyAndKeyWithoutGeneration(): void
    {
        $f = new HandlerFixture();
        $reply = $this->reply($f);
        $reply->loseAck = true;
        try {
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::fail('Owning delivery must observe lost ACK');
        } catch (RuntimeException $error) {
            self::assertSame('lost_native_ack', $error->getMessage());
        }
        $saved = $f->answer->ledger->record;
        $payloads = $f->answer->stored;
        array_unshift($f->messages, $f->message(id: 'native-reply', position: 101, text: 'Newer visible response'));
        $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
        self::assertSame($reply->proposals[0], $reply->proposals[1]);
        self::assertCount(1, $reply->saved);
        self::assertSame(1, $f->answer->sends);
        self::assertSame(1, $f->answer->resolutions);
        self::assertSame($saved, $f->answer->ledger->record);
        self::assertSame($payloads, $f->answer->stored);
    }

    public function testChangedBodySameKeyConflictsBeforeNativeFirstBodyWins(): void
    {
        foreach (['question', 'history'] as $part) {
            $f = new HandlerFixture();
            $reply = $this->reply($f);
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            $index = $part === 'question' ? 0 : 1;
            $old = $f->messages[$index];
            $f->messages[$index] = $f->message(id: $old->id->value, position: $old->position, text: 'changed same-key body', sender: $old->sender, revision: 2);
            try {
                $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
                self::fail('Changed body must conflict in the answer ledger');
            } catch (DomainFailure $failure) {
                self::assertSame('invocation_conflict', $failure->errorCode);
            }
            self::assertSame(1, $f->answer->sends);
            self::assertCount(1, $reply->proposals);
            self::assertCount(1, $reply->saved);
        }
    }

    public function testOneModelOneReplyAndUnknownDispatchNeverRegenerates(): void
    {
        foreach ([false, true] as $unknown) {
            $f = new HandlerFixture();
            $f->answer->unknownTransport = $unknown;
            $reply = $this->reply($f);
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            }
            self::assertSame(1, $f->answer->sends);
            self::assertCount($unknown ? 0 : 1, $reply->saved);
        }
    }

    public function testChangedAnswerBodyCannotReachNativeSlotEvenWithSameInvocationKey(): void
    {
        $f = new HandlerFixture();
        $reply = $this->reply($f);
        $f->changeOutcome = static fn (A\AnswerOutcome $outcome): A\AnswerOutcome => A\AnswerOutcome::fromWire(
            wire: $outcome->toWire()->with('text', 'Changed immutable answer body'),
            validator: $f->answer->validator,
        );
        try {
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::fail('Unsaved changed body must conflict');
        } catch (DomainFailure $failure) {
            self::assertSame('invocation_conflict', $failure->errorCode);
        }
        self::assertSame(1, $f->answer->sends);
        self::assertSame([], $reply->proposals);
        self::assertSame([], $reply->saved);
    }

    public function testCompletedReplayRechecksAccessAndBindingIncludingEmptyPriorHistory(): void
    {
        foreach (['source', 'consent', 'binding', 'revision'] as $change) {
            foreach ([false, true] as $empty) {
                $f = new HandlerFixture();
                if ($empty) {
                    $f->messages = [$f->messages[0]];
                }
                $reply = $this->reply($f);
                $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
                self::assertCount(1, $reply->saved);
                self::assertNotEmpty($f->answer->ledger->record->dependencies->history);
                if ($change === 'binding') {
                    $f->changeBinding();
                } elseif ($change === 'revision') {
                    $f->messages[0] = $f->message(id: 'trigger', position: 100, text: HandlerFixture::corpus()['question'], revision: 2);
                } else {
                    $f->denied[] = $change;
                }
                $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
                self::assertCount(1, $reply->proposals, $change);
                self::assertSame(1, $f->answer->sends);
            }
        }
    }

    public function testHistoryBoundsAreExplicitAndNoFutureMessageBecomesInput(): void
    {
        $f = new HandlerFixture();
        array_unshift($f->messages, $f->message(id: 'future', position: 101, text: 'Future message excluded'));
        $reply = $this->reply($f);
        $f->handler(boundary: $reply->boundary, limits: new A\AnswerLimits(priorTurns: 1, priorBytes: 4096))
            ->handle(invocation: $f->invocation(), reply: $reply);
        self::assertCount(1, $f->lastHistory->priorTurns->turns);
        self::assertSame(HandlerFixture::corpus()['history'], $f->lastHistory->priorTurns->turns[0]->text);
        self::assertStringNotContainsString('Future message excluded', $f->answer->lastRequest->bytes);
    }

    public function testInvalidAttestationsAndWholeContentEgressDenyWithoutSending(): void
    {
        foreach (['expired', 'unbounded', 'wrong-ref', 'wrong-digest', 'QUESTION', 'HISTORY'] as $case) {
            $f = new HandlerFixture();
            $reply = $this->reply($f);
            if (in_array($case, ['QUESTION', 'HISTORY'], true)) {
                $f->answer->egressDeny = $case;
            } else {
                $f->changeAttestation = static fn (A\HistoryReadAttestation $a): A\HistoryReadAttestation => new A\HistoryReadAttestation(
                    scope: $a->scope,
                    orderedTurnsDigest: $case === 'wrong-digest' ? new \KnowKit\Domain\Sha256(str_repeat('0', 64)) : $a->orderedTurnsDigest,
                    ref: $case === 'wrong-ref' ? 'forged:receipt' : $a->ref,
                    expiresAt: match ($case) {
                        'expired' => $f->answer->date(),
                        'unbounded' => $f->answer->date()->modify('+1 hour'),
                        default => $a->expiresAt,
                    },
                );
            }
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::assertSame(0, $f->answer->sends, $case);
            self::assertSame(0, $f->answer->outboundBytes, $case);
            self::assertSame([], $reply->proposals, $case);
        }
    }

    public function testNativeBoundaryRejectsSubstitutedActorSpaceOrPhase(): void
    {
        foreach (['actor', 'space', 'phase'] as $axis) {
            $f = new HandlerFixture();
            $reply = $this->reply($f);
            $context = $this->createStub(OperationContext::class);
            $context->method('operation')->willReturn('message.send');
            $context->method('phase')->willReturn($axis === 'phase' ? Phase::Prepare : Phase::Reauthorize);
            $context->method('space')->willReturn(\Chatom\Spaces\Data\SpaceId::from($axis === 'space' ? 'other-school' : 'school'));
            $context->method('actor')->willReturn(\Chatom\Actors\Data\ActorRef::of(type: 'bot', id: $axis === 'actor' ? 'other-tutor' : 'tutor'));
            $reply->context = $context;
            $f->handler($reply->boundary)->handle(invocation: $f->invocation(), reply: $reply);
            self::assertSame(1, $f->answer->sends);
            self::assertSame([], $reply->saved, $axis);
        }
    }

    public function testManifestAndReceiptRejectMissingSkippedZeroAndChangedFixtures(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/chatom-handler.json');
        $qa->fixtures($manifest);
        self::assertSame(HandlerFixture::corpus()['requiredCaseIds'], $manifest['requiredCaseIds']);
        require_once __DIR__ . '/CitationTest.php';
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                self::assertTrue(method_exists(object_or_class: $class, method: $name));
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(8, Qa::validateCases(cases: array_values($cases), manifest: $manifest));
        foreach (['missing', 'skipped', 'zero'] as $mutation) {
            $changed = array_values($cases);
            if ($mutation === 'missing') {
                array_shift($changed);
            } elseif ($mutation === 'zero') {
                $changed = [];
            } else {
                $changed[0]['status'] = $mutation;
            }
            try {
                Qa::validateCases(cases: $changed, manifest: $manifest);
                self::fail('Incomplete manifest execution accepted');
            } catch (RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        $receipt = $qa->json('tests/chatom/handler/evidence/P7.5-reuse.json');
        foreach ($receipt['sources'] as $source) {
            self::assertSame($source['sha256'], Fixture::inspectedSha256(donor: $source['donor'], relative: $source['path'], sourceUrl: $source['sourceUrl']));
        }
        $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $qa->fixtures($manifest);
    }

    private function reply(HandlerFixture $f, ?ChannelAccess $native = null): NativeReply
    {
        $invocation = $f->invocation();
        $context = $this->createStub(OperationContext::class);
        $context->method('operation')->willReturn('message.send');
        $context->method('phase')->willReturn(Phase::Reauthorize);
        $context->method('space')->willReturn($invocation->space);
        $context->method('actor')->willReturn($invocation->agent);
        $snapshot = $this->nativeValue(ChannelSnapshot::class, ['id' => $invocation->channel, 'space' => $invocation->space]);
        $channel = $this->nativeValue(LockedChannel::class, ['snapshot' => $snapshot]);
        if ($native === null) {
            $native = $this->createStub(ChannelAccess::class);
            $native->method('reauthorize')->willReturn($this->nativeValue(AuthorizedChannel::class, []));
        }

        return new NativeReply(
            boundary: new ChatomReplyBoundary($native),
            context: $context,
            channel: $channel,
            key: $invocation->invocationKey,
        );
    }

    /** Synthetic handles only; production delegates issuance to native Chatom. */
    private function nativeValue(string $class, array $properties): object
    {
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue(objectOrValue: $object, value: $value);
        }

        return $object;
    }
}
