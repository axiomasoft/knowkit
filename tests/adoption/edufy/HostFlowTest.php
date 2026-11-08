<?php

declare(strict_types=1);

namespace KnowKit\Tests\Adoption\Edufy;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKitExample\Edufy\Audience;
use KnowKitExample\Edufy\BindingMode;
use KnowKitExample\Edufy\CoreEvidence;
use KnowKitExample\Edufy\FixtureHost;
use KnowKitExample\Edufy\FixtureResolver;
use KnowKitExample\Edufy\KbEntry;
use KnowKitExample\Edufy\KbRule;
use KnowKitExample\Edufy\LessonConversationBinding;
use KnowKitExample\Edufy\PassageStatus;
use KnowKitExample\Edufy\Sandbox;
use KnowKitExample\Host;
use KnowKitExample\Walkthrough;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/edufy-first-adopter/bootstrap.php';

#[Group('adoption:edufy')]
final class HostFlowTest extends TestCase
{
    public function testCurrentPassageRequiresExactRevisionHashAndAllUnavailableVariantsDeny(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $answer = $f->answer();
            self::assertSame(A\OutcomeCode::Answered, $answer->code);
            $citation = $answer->answeredContent()->citations[0]->canonical();
            $read = $f->reader->reads[0];
            self::assertSame(['learner:1', 'class:1', 'topic:1', 'entry:1', 'section:1', 'passage:1'], $read);
            $f->reader->revision = 'passage:2';
            $this->metadata($f->answer());
            self::assertSame($citation, $answer->answeredContent()->citations[0]->canonical());
            $f->reader->revision = 'passage:1';
            $f->reader->reportedHash = str_repeat('a', 64);
            $this->metadata($f->answer());
            $f->reader->reportedHash = null;
            foreach (PassageStatus::cases() as $status) {
                if ($status === PassageStatus::Available) {
                    continue;
                }
                $f->reader->status = $status;
                $this->metadata($f->answer());
                self::assertNull($f->authority->materials->current($f->authority->inputs, $f->authority->passage)->text);
            }
            $f->reader->throws = true;
            $this->metadata($f->answer());
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testTeacherOnlyIsExcludedForStudentButUsesSameCoreForTeacher(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $teacher = new KbEntry('teacher', 'entry:1', new D\SourceVersionId(Host::id('demo-version')), Audience::TeacherOnly);
            $f->authority->resolver = new FixtureResolver([new KbRule('topic:1', BindingMode::Additive, [$teacher])]);
            $denied = $f->answer();
            self::assertSame(A\OutcomeCode::NoEvidence, $denied->code);
            self::assertNull($denied->answeredContent());
            self::assertSame(0, $f->transport->sends);
            $resolution = $f->authority->resolver->resolve('topic:1', $f->authority->kbContext);
            self::assertSame([$teacher->sourceVersion], $resolution->sourceVersions(Audience::TeacherOnly));
        });
        Sandbox::run(function (FixtureHost $f): void {
            $f->authority->resolver = new FixtureResolver([new KbRule('topic:1', BindingMode::Additive, [
                new KbEntry('teacher', 'entry:1', new D\SourceVersionId(Host::id('demo-version')), Audience::TeacherOnly),
            ])]);
            $answer = $f->answer();
            self::assertSame(A\OutcomeCode::Answered, $answer->code);
            self::assertStringStartsWith('Teacher-only answer:', $answer->answeredContent()->text);
            self::assertSame(1, $f->transport->sends);
        }, audience: Audience::TeacherOnly);
    }

    public function testClassConsentAndUnknownPolicyRevokeBeforeSendAndCompletedReplay(): void
    {
        foreach (['classAllowed', 'consentAllowed', 'known'] as $field) {
            Sandbox::run(function (FixtureHost $f) use ($field): void {
                $f->authority->onBeforeSend = static function () use ($f, $field): void {
                    $f->authority->{$field} = false;
                };
                $this->metadata($f->answer());
                self::assertSame(0, $f->transport->sends);
                self::assertSame(0, $f->transport->outboundBytes);
            });
            Sandbox::run(function (FixtureHost $f) use ($field): void {
                self::assertSame(A\OutcomeCode::Answered, $f->answer()->code);
                $f->authority->{$field} = false;
                $this->metadata($f->answer());
                self::assertSame(1, $f->transport->sends);
            });
        }
    }

    public function testModelChangeAffectsOnlyNewKeyAndLostAckUsesExactSavedReply(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $request = $f->request();
            $first = $f->answer($request);
            self::assertSame(A\OutcomeCode::Answered, $first->code);
            $receipt = $f->replies->publish($request, $first);
            $f->bindings->model = 'fixture-model:2';
            $again = $f->answer($request);
            self::assertSame(Jcs::canonicalize($first->toWire()), Jcs::canonicalize($again->toWire()));
            self::assertSame($receipt, $f->replies->publish($request, $again));
            self::assertSame('fixture-model:1', $again->observedModelRef);
            self::assertSame(1, $f->bindings->resolutions);
            self::assertSame(1, $f->transport->sends);
            $future = $f->answer($f->request('native:turn:2'));
            self::assertSame('fixture-model:2', $future->observedModelRef);
            self::assertNotEquals($first->answeredContent()->modelBindingDigest, $future->answeredContent()->modelBindingDigest);
            self::assertSame(2, $f->transport->sends);
            try {
                $f->answer($f->request(question: 'changed support'));
                self::fail('Changed original request reused the key');
            } catch (D\DomainFailure $failure) {
                self::assertSame('invocation_conflict', $failure->errorCode);
            }
            self::assertSame(2, $f->transport->sends);
        });
    }

    public function testLessonConversationAndTutorModelAreSeparateBindings(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $lesson = $f->bindings->conversationFor('lesson:1');
            $model = $f->bindings->modelFor($lesson->tutor);
            $f->bindings->model = 'fixture-model:2';
            self::assertSame($lesson, $f->bindings->conversationFor('lesson:1'));
            self::assertEquals($model, $f->bindings->modelFor($lesson->tutor));
            $request = $f->request();
            $answer = $f->answer($request);
            $f->replies->beforeMutation = static function () use ($f, $lesson): void {
                $f->bindings->lessonBinding = new LessonConversationBinding($lesson->lesson, 'channel:2',
                    $lesson->kb, $lesson->tutor, $lesson->route, '2');
            };
            self::assertSame(['fixture' => true, 'status' => 'denied'], $f->replies->publish($request, $answer));
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testNonSchoolProfileUsesIdenticalCoreWithoutSchoolState(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            self::assertNull($f->authority->inputs->learner);
            self::assertNull($f->authority->inputs->class);
            self::assertSame('handbook.read', $f->request()->scope->purpose);
            $answer = $f->answer();
            self::assertSame(A\OutcomeCode::Answered, $answer->code);
            self::assertSame('published', $f->replies->publish($f->request(), $answer)['status']);
            self::assertSame(1, $f->transport->sends);
        }, 'workplace');
    }

    public function testCrossProjectSameIdsCannotLeakSelectionCitationUsageOrAnswer(): void
    {
        Sandbox::run(function (FixtureHost $a): void {
            $answerA = $a->answer();
            $requestA = $a->request();
            $selectionA = $a->core->lastSelection;
            Sandbox::run(function (FixtureHost $b) use ($a, $answerA, $requestA, $selectionA): void {
                self::assertEquals($requestA->invocationKey, $b->request()->invocationKey);
                self::assertNotEquals($requestA->scope->hostScopeHash, $b->request()->scope->hostScopeHash);
                try {
                    $b->ledger->find($requestA->invocationRef());
                    self::fail('Foreign tenant was accepted');
                } catch (D\DomainFailure $failure) {
                    self::assertSame('scope_mismatch', $failure->errorCode);
                }
                self::assertNull($b->answer($requestA)->answeredContent());
                self::assertSame(0, $b->transport->sends);
                self::assertSame('denied', $b->replies->publish($b->request(), $answerA)['status']);
                $answerB = $b->answer();
                self::assertSame(A\OutcomeCode::Answered, $answerB->code);
                self::assertNotSame($answerA->usageRef, $answerB->usageRef);
                self::assertNotSame($selectionA->scopeBindingDigest()->value, $b->core->lastSelection->scopeBindingDigest()->value);
                self::assertNotSame($answerA->answeredContent()->citations[0]->canonical(), $answerB->answeredContent()->citations[0]->canonical());
                $scope = $b->request()->scope;
                $sharedCache = new CoreEvidence($b->authority, $a->core->cache);
                $cached = $sharedCache->retrieve($scope, new D\Query('support'), new D\Limit(1));
                self::assertSame(D\Outcome::Success, $cached->result->outcome);
                foreach ($cached->result->data->candidateRefs() as $ref) {
                    self::assertEquals($scope->tenantId, $ref->revisionRef->sourceRef->tenantId);
                }
                $this->assertForeignSelectionDenied($b, $selectionA);
                self::assertSame(1, $a->transport->sends);
                self::assertSame(1, $b->transport->sends);
            }, 'schoolB');
        });
    }

    public function testQuestionHistoryAndProcessingDenialsSendNoBytes(): void
    {
        foreach (['QUESTION', 'HISTORY', 'processing'] as $denied) {
            Sandbox::run(function (FixtureHost $f) use ($denied): void {
                if ($denied === 'processing') {
                    $f->authority->processingAllowed = false;
                }
                if ($denied !== 'processing') {
                    $f->authority->deniedEgressCategories = [$denied];
                }
                $this->metadata($f->answer($f->request(withHistory: true)));
                self::assertSame(0, $f->transport->sends);
                self::assertSame(0, $f->transport->outboundBytes);
            });
        }
    }

    public function testSourceRevokeAndConsentRevokeAfterProviderBeforeReplyNeverPublish(): void
    {
        foreach (['source', 'consent'] as $field) {
            Sandbox::run(function (FixtureHost $f) use ($field): void {
                $request = $f->request();
                $f->transport->afterSend = static function () use ($f, $field): void {
                    if ($field === 'consent') {
                        $f->authority->consentAllowed = false;
                        return;
                    }
                    $walk = new Walkthrough($f->runtime);
                    $walk->require($walk->call('lifecycle revoke', ['sourceId' => Host::id('demo-source'), 'reason' => 'source_revoked'], 'revoke', '0'));
                };
                $answer = $f->answer($request);
                $this->metadata($answer);
                self::assertSame('denied', $f->replies->publish($request, $answer)['status']);
                self::assertSame(1, $f->transport->sends);
                $this->metadata($f->answer($request));
                self::assertSame(1, $f->transport->sends);
            });
        }
    }

    public function testPartialReplyAndOutOfOrderWorkerCannotRegenerate(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $f->transport->partial = true;
            $request = $f->request();
            self::assertSame(A\OutcomeCode::TransportUnknown, $f->answer($request)->code);
            $record = $f->ledger->find($request->invocationRef());
            self::assertSame(A\InvocationState::Unknown, $record->state);
            $this->metadata($f->answer($request));
            try {
                $f->ledger->markSending($record->claim, $f->transport->lastRequest->identity);
                self::fail('Out-of-order worker rewound unknown');
            } catch (D\DomainFailure) {
                self::assertSame(1, $f->transport->sends);
            }
        });
    }

    public function testSourceRevokeAfterDurableSendingMarkStopsDispatch(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $request = $f->request();
            $checks = 0;
            $f->authority->onBeforeSend = static function () use ($f, &$checks): void {
                $checks++;
                if ($checks < 2) {
                    return;
                }
                $walk = new Walkthrough($f->runtime);
                $walk->require($walk->call('lifecycle revoke', ['sourceId' => Host::id('demo-source'),
                    'reason' => 'source_revoked'], 'revoke-before-send', '0'));
            };
            $outcome = $f->answer($request);
            self::assertSame(A\OutcomeCode::Denied, $outcome->code);
            $this->metadata($outcome);
            self::assertSame(2, $checks);
            self::assertSame(0, $f->transport->sends);
            self::assertSame(0, $f->transport->outboundBytes);
            self::assertSame(A\InvocationState::Completed, $f->ledger->find($request->invocationRef())->state);
        });
    }

    public function testSavedAnswerDoesNotGrantPublicationHistoryOrNewAudience(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $request = $f->request(withHistory: true);
            $answer = $f->answer($request);
            self::assertSame(A\OutcomeCode::Answered, $answer->code);
            $f->authority->historyAllowed = false;
            $this->metadata($f->answer($request));
            self::assertSame('denied', $f->replies->publish($request, $answer)['status']);
            $f->authority->historyAllowed = true;
            $f->authority->publicationAllowed = false;
            self::assertSame('denied', $f->replies->publish($request, $answer)['status']);
            $f->authority->publicationAllowed = true;
            $values = get_object_vars($f->authority->inputs);
            $values['actingAs'] = 'another-subject';
            $f->authority->inputs = new \KnowKitExample\Edufy\HostScope(...$values);
            $this->metadata($f->answer($request));
            self::assertSame('denied', $f->replies->publish($request, $answer)['status']);
            self::assertSame(1, $f->transport->sends);
        });
    }

    public function testReplyOwnerRejectsAlteredCheckedBodyAndRevokeAtMutationBoundary(): void
    {
        Sandbox::run(function (FixtureHost $f): void {
            $request = $f->request();
            $answer = $f->answer($request);
            $wire = $answer->toWire()->with('text', 'Forged body');
            $forged = A\AnswerOutcome::fromWire($wire, $f->runtime->host->validator);
            self::assertSame('denied', $f->replies->publish($request, $forged)['status']);
            $f->replies->beforeMutation = static function () use ($f): void {
                $f->authority->consentAllowed = false;
            };
            self::assertSame('denied', $f->replies->publish($request, $answer)['status']);
            self::assertSame(1, $f->transport->sends);
        });
    }

    private function metadata(A\AnswerOutcome $outcome): void
    {
        self::assertNull($outcome->answeredContent());
        self::assertFalse($outcome->toWire()->has('text'));
        self::assertFalse($outcome->toWire()->has('citations'));
    }

    private function assertForeignSelectionDenied(FixtureHost $host, D\Selection $foreign): void
    {
        $request = new D\DisclosureRequest(new JsonObject([
            'selectionId' => $foreign->id()->value,
            'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $foreign->candidateRefs()),
            'disclosureAttemptId' => Host::id('foreign-attempt'),
        ]), $host->runtime->host->validator);
        try {
            $host->core->disclose($host->request()->scope, $request);
            self::fail('Foreign selection was accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Denied, $failure->outcome);
        }
    }
}
