<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Contracts;

use DateTimeImmutable;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ModelTransportRegistry;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:contracts')]
final class ContractsTest extends TestCase
{
    public function testEveryOutcomeVariantHasExactMetadataAndNoErrorPayload(): void
    {
        $invocation = Fixture::request()->invocationRef();
        $observed = [];
        foreach (A\OutcomeCode::cases() as $code) {
            $outcome = Fixture::outcome($code);
            $wire = $outcome->toWire();
            $observed[] = $wire->get('type');
            foreach (['invocationRef', 'certainty', 'observedModelRef', 'usageRef', 'fixture'] as $key) {
                self::assertTrue($wire->has($key), $key);
            }
            self::assertSame($code === A\OutcomeCode::Answered, $wire->has('text'));
            self::assertSame($code === A\OutcomeCode::Answered, $wire->has('citations'));
            if ($code !== A\OutcomeCode::Answered) {
                self::assertNull($outcome->answeredContent());
                self::assertStringNotContainsString('Grounded synthetic answer', Jcs::canonicalize($wire));
            }
        }
        self::assertSame(Fixture::corpus()['variants'], $observed);
        $cancelled = new A\AnswerOutcome(A\OutcomeCode::Cancelled, $invocation, A\DispatchCertainty::Sent, sent: true);
        self::assertTrue($cancelled->toWire()->get('sent'));
        self::assertEquals(Fixture::corpus()['citation'], json_decode(Fixture::citation()->canonical(), true));
    }

    public function testMalformedVariantsAndPartialTransportOutputCannotBecomeAnswered(): void
    {
        $key = Fixture::request()->invocationRef();
        foreach ([
            fn () => new A\AnswerOutcome(A\OutcomeCode::Denied, $key, A\DispatchCertainty::KnownNotSent, content: Fixture::content(), reason: A\DenialReason::Revoked),
            fn () => new A\AnswerOutcome(A\OutcomeCode::Answered, $key, A\DispatchCertainty::Sent, content: Fixture::content()),
            fn () => new A\AnswerOutcome(A\OutcomeCode::Answered, $key, A\DispatchCertainty::Unknown, usageRef: 'usage:1', content: Fixture::content()),
            fn () => new A\AnswerOutcome(A\OutcomeCode::Refused, $key, A\DispatchCertainty::Sent, reason: A\DenialReason::Revoked),
            fn () => new A\AnswerOutcome(A\OutcomeCode::Cancelled, $key, A\DispatchCertainty::Unknown, sent: true),
            fn () => new A\AnswerOutcome(A\OutcomeCode::TransportUnknown, $key, A\DispatchCertainty::Sent),
            fn () => new A\AnswerOutcome(A\OutcomeCode::NoEvidence, $key, A\DispatchCertainty::KnownNotSent, component: A\UnavailableComponent::Transport),
            fn () => new A\TransportResult(A\DispatchCertainty::Unknown, 'partial protected text', false, new A\UsageObservation()),
            fn () => new A\TransportResult(A\DispatchCertainty::Unknown, 'partial protected text', true, new A\UsageObservation()),
            fn () => new A\AnswerOutcome(A\OutcomeCode::Answered, new A\InvocationRef(Fixture::scope(['tenantId' => '01JC0000000000000000000009'])->tenantId, $key->key), A\DispatchCertainty::Sent, usageRef: 'usage:1', content: Fixture::content()),
        ] as $make) {
            $this->rejects($make);
        }
        self::assertNull((new A\UsageObservation())->inputTokens);
        self::assertNull((new A\UsageObservation())->outputTokens);
        self::assertFalse(property_exists(A\TransportResult::class, 'citations'));
    }

    public function testUtf8ByteBoundsDefaultsAndLowerHostLimitsRejectWithoutClamping(): void
    {
        $limits = new A\AnswerLimits();
        self::assertSame(Fixture::corpus()['defaults'], iterator_to_array($limits->toWire()));
        foreach (Fixture::corpus()['utf8'] as $vector) {
            self::assertSame($vector['bytes'], strlen($vector['text']));
        }
        self::assertSame(4096, strlen(Fixture::request(['question' => str_repeat('Я', 2048)])->question));
        $turns = new A\BoundedTurns([new A\PriorTurn(A\MessageRole::User, str_repeat('🙂', 4096))]);
        self::assertSame(16384, $turns->byteLength());
        self::assertCount(12, (new A\BoundedTurns(array_fill(0, 12, new A\PriorTurn(A\MessageRole::User, 'x'))))->turns);
        $limits->assertInput(str_repeat('漢', 10922) . 'ab', str_repeat('🙂', 4096));
    }

    public function testInvalidUtf8AndByteBoundsNeverClampOrRaiseDefaults(): void
    {
        $limits = new A\AnswerLimits();
        foreach ([
            fn () => Fixture::request(['question' => str_repeat('Я', 2048) . 'a']),
            fn () => Fixture::request(['question' => "\xC3\x28"]),
            fn () => Fixture::request(['question' => ' ']),
            fn () => new A\PriorTurn(A\MessageRole::System, 'untrusted policy'),
            fn () => new A\BoundedTurns(array_fill(0, 13, new A\PriorTurn(A\MessageRole::User, 'x'))),
            fn () => new A\BoundedTurns([new A\PriorTurn(A\MessageRole::User, str_repeat('🙂', 4096)), new A\PriorTurn(A\MessageRole::Assistant, 'x')]),
            fn () => new A\BoundedTurns([1 => new A\PriorTurn(A\MessageRole::User, 'x')]),
            fn () => new A\BoundedTurns(['raw history']),
            fn () => Fixture::request(['limits' => new A\AnswerLimits(priorTurns: 0)]),
            fn () => new A\AnswerLimits(questionBytes: 4097),
            fn () => new A\AnswerLimits(deadlineSeconds: 121),
            fn () => new A\AnswerLimits(inputTokens: 0),
            fn () => new A\AnswerLimits(inputTokens: PHP_INT_MAX),
            fn () => $limits->assertInput(str_repeat('a', 32769), ''),
            fn () => $limits->assertInput('', str_repeat('a', 16385)),
            fn () => $limits->assertInput("\xFF", ''),
            fn () => $limits->assertOutput(str_repeat('a', 65537)),
            fn () => new A\GenerationSettings(temperature: '2.1'),
            fn () => new A\GenerationSettings(topP: '1.1'),
            fn () => new A\GenerationSettings(seed: PHP_INT_MAX),
        ] as $make) {
            $this->rejects($make);
        }
    }

    public function testTokenLimitsRequireExactPinnedTokenizerAndKeepByteLimits(): void
    {
        $tokenizer = new ExactTokenizer();
        $identity = ExactTokenizer::identity();
        $limits = new A\AnswerLimits(inputTokens: 2, outputTokens: 2);
        $limits->assertInput('漢🙂', '', $tokenizer, $identity);
        $limits->assertOutput('漢🙂', $tokenizer, $identity);
        foreach ([
            fn () => $limits->assertInput('x', ''),
            fn () => (new A\AnswerLimits())->assertOutput('x'),
            fn () => $limits->assertInput('漢🙂a', '', $tokenizer, $identity),
            fn () => $limits->assertOutput('漢🙂a', $tokenizer, $identity),
            fn () => $limits->assertInput('x', '', new ExactTokenizer(false), $identity),
            fn () => $limits->assertInput('x', '', $tokenizer, new D\TokenizerIdentity(new D\AdapterId('unknown'), '1', $identity->vocabularyDigest)),
            fn () => (new A\AnswerLimits(inputBytes: 1))->assertInput('漢', '', $tokenizer, $identity),
            fn () => new A\ModelCapabilities(new D\Sha256(str_repeat('1', 64)), new DateTimeImmutable('2030-01-01'), true, 3),
            fn () => new A\ModelCapabilities(new D\Sha256(str_repeat('1', 64)), new DateTimeImmutable('2030-01-01'), true, PHP_INT_MAX, tokenizer: $identity),
        ] as $make) {
            $this->rejects($make);
        }
    }

    public function testPinnedModelWindowAndOutputByteCeilingRemainIndependent(): void
    {
        $tokenizer = new ExactTokenizer();
        $identity = ExactTokenizer::identity();
        $limits = new A\AnswerLimits(inputTokens: 2, outputTokens: 2);
        $capabilities = new A\ModelCapabilities(new D\Sha256(str_repeat('1', 64)), new DateTimeImmutable('2030-01-01'), true, 3, 4, $identity);
        $binding = Fixture::binding(['capabilities' => $capabilities, 'settings' => new A\GenerationSettings(2)]);
        $binding->assertInput('漢🙂', '', $limits, $tokenizer);
        $this->rejects(fn () => $binding->assertInput('漢🙂a', '', new A\AnswerLimits(), $tokenizer));
        $this->rejects(fn () => $binding->assertInput('x', '', $limits));
        $this->rejects(fn () => Fixture::binding()->assertInput('x', '', $limits, $tokenizer));
        try {
            (new A\AnswerLimits())->assertOutput(str_repeat('a', 65536));
            self::fail('Output tokens require an explicit tokenizer');
        } catch (D\DomainFailure $failure) {
            self::assertSame('tokenizer_required', $failure->errorCode);
            self::assertSame(D\Outcome::Incompatible, $failure->outcome);
        }
    }

    public function testMissingConfigurationAndReadinessPerformZeroModelIo(): void
    {
        $registry = new ModelTransportRegistry();
        $spy = new SpyTransport();
        $registry->register($spy);
        $profiles = [
            [new EffectiveProfile(ProfileMode::Production), A\UnavailableComponent::Transport],
            [new EffectiveProfile(ProfileMode::Production, new A\TransportDescriptor('absent', '1', true)), A\UnavailableComponent::Transport],
            [new EffectiveProfile(ProfileMode::Production, new A\TransportDescriptor('host-http', '2', true)), A\UnavailableComponent::Transport],
            [new EffectiveProfile(ProfileMode::Production, $spy->descriptor()), A\UnavailableComponent::HttpClient],
            [new EffectiveProfile(ProfileMode::Production, $spy->descriptor(), true), A\UnavailableComponent::Credentials],
            [new EffectiveProfile(ProfileMode::Production, $spy->descriptor(), true, new D\CredentialRef('host:key', false)), A\UnavailableComponent::Credentials],
        ];
        foreach ($profiles as [$profile, $component]) {
            $readiness = $registry->readiness($profile);
            self::assertFalse($readiness->configured());
            $outcome = $readiness->outcome(Fixture::request()->invocationRef());
            self::assertSame(A\OutcomeCode::Unavailable, $outcome->code);
            self::assertSame($component, $outcome->component);
            self::assertSame(A\DispatchCertainty::KnownNotSent, $outcome->certainty);
            self::assertFalse($outcome->toWire()->has('text'));
            $this->rejects(fn () => $registry->resolve($profile));
        }
        $ready = new EffectiveProfile(ProfileMode::Production, $spy->descriptor(), true, new D\CredentialRef('host:key', true));
        self::assertTrue($registry->readiness($ready)->configured());
        self::assertNull($registry->readiness($ready)->outcome(Fixture::request()->invocationRef()));
        self::assertSame($spy, $registry->resolve($ready));
        self::assertSame(0, $spy->sends);
        self::assertSame(0, $spy->bytes);
        $this->rejects(fn () => $registry->register($spy));
    }

    public function testScriptedTransportIsTestOnlyAndAlwaysMarksFixturesWithoutRetries(): void
    {
        $descriptor = ScriptedTransport::testDescriptor();
        self::assertFalse($descriptor->production);
        $this->rejects(fn () => new EffectiveProfile(ProfileMode::Production, $descriptor));
        $this->rejects(fn () => new ScriptedTransport(new EffectiveProfile(ProfileMode::Production), []));
        $profile = new EffectiveProfile(ProfileMode::Test, $descriptor, true);
        $this->rejects(fn () => new ScriptedTransport($profile, ['raw result']));
        $result = new A\TransportResult(A\DispatchCertainty::Sent, 'synthetic final', true, new A\UsageObservation(10, 2));
        $scripted = new ScriptedTransport($profile, [$result]);
        $registry = new ModelTransportRegistry();
        $registry->register($scripted);
        self::assertTrue($registry->readiness($profile)->configured());
        self::assertSame(0, $scripted->sendCount());
        $body = '{"messages":[]}';
        $call = Fixture::call(['completeRequestBytes' => $body, 'binding' => Fixture::binding(['transport' => $descriptor])]);
        $request = new A\TransportRequest($call, $body);
        self::assertTrue($scripted->send($request, Fixture::cancellation(true))->fixture);
        self::assertSame(0, $scripted->sendCount());
        self::assertSame(0, $scripted->outboundBytes());
        $observed = $registry->resolve($profile)->send($request, Fixture::cancellation());
        self::assertTrue($observed->fixture);
        self::assertSame('synthetic final', $observed->text);
        self::assertSame(1, $scripted->sendCount());
        self::assertSame(strlen($body), $scripted->outboundBytes());
        $this->rejects(fn () => $scripted->send($request, Fixture::cancellation()));
        self::assertSame(1, $scripted->sendCount());
        $this->rejects(fn () => $scripted->send(new A\TransportRequest(Fixture::call(), '{"messages":[{"role":"user","content":"synthetic question"}]}'), Fixture::cancellation()));
    }

    public function testFingerprintGoldenAndEveryOriginalFieldPreserveFullHostScope(): void
    {
        $request = Fixture::request();
        $golden = Fixture::corpus()['golden'];
        self::assertSame($golden['orderedTurnsDigest'], $request->priorTurns->digest()->value);
        self::assertSame($golden['originalRequestDigest'], $request->originalRequestDigest()->value);
        self::assertSame($golden['requestFingerprint'], $request->fingerprint(Fixture::binding())->value);
        foreach ([
            ['tenantId' => '01JC0000000000000000000009'],
            ['kbId' => '01JC0000000000000000000009'],
            ['actorId' => '01JC0000000000000000000009'],
            ['purpose' => 'lesson.review'],
            ['policyEpoch' => '43'],
            ['hostScopeHash' => str_repeat('9', 64)],
        ] as $scopeChange) {
            $scope = Fixture::scope($scopeChange);
            $this->rejects(fn () => Fixture::binding()->requireScope($scope));
            $changed = Fixture::request(['scope' => $scope]);
            self::assertNotSame($golden['originalRequestDigest'], $changed->originalRequestDigest()->value);
            self::assertNotSame($golden['requestFingerprint'], $changed->fingerprint(Fixture::binding(['scope' => $scope]))->value);
            $this->rejects(fn () => $changed->fingerprint(Fixture::binding()));
        }
    }

    public function testOriginalFieldsAndPinnedDigestChangeAdmissionFingerprint(): void
    {
        $request = Fixture::request();
        $golden = Fixture::corpus()['golden'];
        foreach ([
            ['question' => 'changed'],
            ['priorTurns' => new A\BoundedTurns(array_reverse($request->priorTurns->turns))],
            ['priorTurns' => new A\BoundedTurns([new A\PriorTurn(A\MessageRole::Assistant, 'changed')])],
            ['modelBindingRef' => new A\ModelBindingRef('tutor:other')],
            ['retrievalProfileRef' => new A\ProfileRef('other', '7', Fixture::profile()->digest)],
            ['retrievalProfileRef' => new A\ProfileRef('retrieval:lesson', '8', Fixture::profile()->digest)],
            ['retrievalProfileRef' => new A\ProfileRef('retrieval:lesson', '7', new D\Sha256(str_repeat('9', 64)))],
            ['limits' => new A\AnswerLimits(deadlineSeconds: 60)],
        ] as $change) {
            $changed = Fixture::request($change);
            self::assertNotSame($golden['originalRequestDigest'], $changed->originalRequestDigest()->value);
            self::assertNotSame($golden['requestFingerprint'], $changed->fingerprint(Fixture::binding(['requestedRef' => $changed->modelBindingRef]))->value);
        }
        self::assertNotSame($golden['requestFingerprint'], $request->fingerprint(Fixture::binding(['bindingDigest' => new D\Sha256(str_repeat('9', 64))]))->value);
        self::assertSame($golden['originalRequestDigest'], Fixture::request(['invocationKey' => new A\InvocationKey('new-key')])->originalRequestDigest()->value);
    }

    public function testModelCallIdentityBindsRawBytesDeploymentAndAllAuditMetadata(): void
    {
        $call = Fixture::call();
        $wire = Jcs::canonicalize($call->toWire());
        self::assertStringNotContainsString('synthetic question', $wire);
        self::assertStringNotContainsString('Grounded synthetic answer', $wire);
        self::assertSame(hash('sha256', '{"messages":[{"role":"user","content":"synthetic question"}]}'), $call->requestBytesDigest->value);
        foreach ([
            ['completeRequestBytes' => '{"messages":[]}'],
            ['template' => new A\ProfileRef('grounding', '2', Fixture::profile()->digest)],
            ['messages' => [new A\MessageDigest(A\MessageRole::System, new D\Sha256(str_repeat('8', 64)))]],
            ['binding' => Fixture::binding(['region' => 'us-test'])],
            ['binding' => Fixture::binding(['account' => 'account:other'])],
            ['binding' => Fixture::binding(['modelRevision' => 'revision-next'])],
            ['binding' => Fixture::binding(['settings' => new A\GenerationSettings(1024, '0.5')])],
            ['egressDecision' => new A\EgressDecisionRef('egress:002', 'policy-43', new DateTimeImmutable('2026-11-08T00:00:00Z'), new DateTimeImmutable('2026-11-08T00:01:00Z'))],
            ['dependencies' => new A\DependencyManifest(new D\Sha256(str_repeat('5', 64)))],
            ['deadline' => new DateTimeImmutable('2026-11-08T00:01:00Z')],
        ] as $change) {
            self::assertNotSame($call->digest()->value, Fixture::call($change)->digest()->value);
        }
    }

    public function testOutboundTamperingCrossTenantDependenciesAndSecretPersistenceReject(): void
    {
        $call = Fixture::call();
        $this->rejects(fn () => new A\TransportRequest($call, 'tampered'));
        $this->rejects(fn () => Fixture::call(['messages' => []]));
        $this->rejects(fn () => Fixture::call(['messages' => ['raw protected text']]));
        $this->rejects(fn () => Fixture::call(['dependencies' => new A\DependencyManifest(new D\Sha256(str_repeat('5', 64)), evidence: ['raw evidence'])]));
        $this->rejects(fn () => new A\AnsweredContent('text', ['raw citation'], Fixture::binding()->bindingDigest, new D\Sha256(str_repeat('2', 64))));
        $this->rejects(fn () => Fixture::call(['deadline' => new DateTimeImmutable('2026-11-07T00:00:00Z')]));
        $foreign = new A\HistoryDependency(Fixture::scope(['tenantId' => '01JC0000000000000000000009'])->tenantId, 'history:1', '1', new D\Sha256(str_repeat('5', 64)));
        $this->rejects(fn () => Fixture::call(['dependencies' => new A\DependencyManifest(new D\Sha256(str_repeat('5', 64)), history: [$foreign])]));
        $this->rejects(fn () => Fixture::binding()->requireCurrent(new DateTimeImmutable('2030-01-01T00:00:00Z')));
        $credential = new A\Credential('ephemeral synthetic credential');
        self::assertStringNotContainsString('ephemeral', print_r($credential, true));
        $this->rejects(fn () => serialize($credential));
    }

    public function testHistoryNeedsCurrentFullScopeAttestationAndCannotBorrowAnotherSlice(): void
    {
        $turns = Fixture::turns();
        $now = new DateTimeImmutable('2026-11-08T00:00:00Z');
        $this->rejects(fn () => $turns->requireCurrentAttestation(Fixture::scope(), $now));
        $receipt = new A\HistoryReadAttestation(Fixture::scope(), $turns->digest(), 'history-read:1', $now->modify('+1 minute'));
        $attested = new A\BoundedTurns($turns->turns, $receipt);
        $attested->requireCurrentAttestation(Fixture::scope(), $now);
        $this->rejects(fn () => $attested->requireCurrentAttestation(Fixture::scope(['hostScopeHash' => str_repeat('9', 64)]), $now));
        $this->rejects(fn () => $attested->requireCurrentAttestation(Fixture::scope(), $now->modify('+1 minute')));
        $this->rejects(fn () => (new A\BoundedTurns(array_reverse($turns->turns), $receipt))->requireCurrentAttestation(Fixture::scope(), $now));
        (new A\BoundedTurns())->requireCurrentAttestation(Fixture::scope(), $now);
        self::assertSame($turns->digest()->value, $attested->digest()->value);
    }

    public function testModelAndEmbeddingBindingsAreIndependentAndPackageDagStaysOptional(): void
    {
        $embedding = new D\EmbeddingSpace('embedding-provider', 'embedding-model', 'embedding-v1', 3, 'l2', 'index-v1', 'document', 'query');
        $embeddingDigest = $embedding->digest()->value;
        $binding = Fixture::binding();
        $newBinding = Fixture::binding(['model' => 'new-chat-model', 'bindingDigest' => new D\Sha256(str_repeat('8', 64))]);
        self::assertSame($embeddingDigest, $embedding->digest()->value);
        self::assertNotSame($binding->bindingDigest->value, $newBinding->bindingDigest->value);
        $newEmbedding = new D\EmbeddingSpace('embedding-provider', 'embedding-model', 'embedding-v2', 3, 'l2', 'index-v1', 'document', 'query');
        self::assertNotSame($embeddingDigest, $newEmbedding->digest()->value);
        self::assertSame(Fixture::corpus()['bindingDigest'], $binding->bindingDigest->value);
        $root = json_decode((string) file_get_contents(Fixture::root() . '/composer.json'), true);
        $core = json_decode((string) file_get_contents(Fixture::root() . '/packages/core/composer.json'), true);
        $answer = json_decode((string) file_get_contents(Fixture::root() . '/packages/answer/composer.json'), true);
        self::assertArrayNotHasKey('knowkit/answer', $root['require-dev']);
        self::assertArrayNotHasKey('psr/http-client', $core['require']);
        foreach (['knowkit/core', 'psr/http-client', 'psr/http-factory'] as $package) {
            self::assertArrayHasKey($package, $answer['require']);
        }
        self::assertTrue(interface_exists(\Psr\Http\Client\ClientInterface::class));
        self::assertTrue(interface_exists(\Psr\Http\Message\RequestFactoryInterface::class));
        self::assertTrue(interface_exists(\KnowKit\Answer\GroundedAnswering::class));
        foreach (['ModelTransport', 'ModelBindingResolver', 'AnswerBudget', 'InvocationLedger', 'CredentialResolver'] as $port) {
            self::assertTrue(interface_exists('KnowKit\\Answer\\Port\\' . $port));
        }
        self::assertTrue((new \ReflectionClass(A\ResolvedModelBinding::class))->isReadOnly());
    }

    public function testLedgerAndBudgetContractsPinTenantKeyAndNullableUsage(): void
    {
        $key = Fixture::request()->invocationRef();
        $claim = new A\LedgerClaim($key, new D\Decimal('1'), 'worker:1', new DateTimeImmutable('2030-01-01'), new D\Decimal('2'));
        $pins = new A\InvocationPins(Fixture::binding(), Fixture::profile(), Fixture::request()->originalRequestDigest());
        $make = fn (A\InvocationState $state, ?D\Sha256 $call = null, ?A\LedgerClaim $lease = null) => new A\InvocationRecord(
            $key,
            Fixture::request()->originalRequestDigest(),
            Fixture::request()->fingerprint($pins->binding),
            $pins,
            $state,
            $lease ?? $claim,
            $call,
        );
        $preparing = $make(A\InvocationState::Preparing);
        self::assertSame($pins, $preparing->pins);
        $this->rejects(fn () => $make(A\InvocationState::Sending));
        $this->rejects(fn () => $make(A\InvocationState::Unknown));
        $unknown = $make(A\InvocationState::Unknown, Fixture::call()->digest());
        self::assertSame(A\InvocationState::Unknown, $unknown->state);
        self::assertTrue((new \ReflectionClass($unknown))->isReadOnly());
        $foreignKey = new A\InvocationRef($key->tenantId, new A\InvocationKey('another-key'));
        $foreignClaim = new A\LedgerClaim($foreignKey, new D\Decimal('1'), 'worker:1', $claim->leaseUntil, new D\Decimal('2'));
        $this->rejects(fn () => $make(A\InvocationState::Preparing, lease: $foreignClaim));
        $this->rejects(fn () => new A\Estimate(Fixture::scope(), -1, null, 1, 1));
        $this->rejects(fn () => new A\UsageObservation(outputTokens: -1));
        $reservation = new A\BudgetReservation($key, 'reservation:1', A\ReservationStatus::Unknown);
        self::assertSame($key, $reservation->invocationRef);
        self::assertSame(A\ReservationStatus::Unknown, $reservation->status);
        self::assertNull((new A\UsageObservation())->providerRequestId);
    }

    public function testCheckpointCannotReplaceOriginalRequestDigestOrPinnedTenant(): void
    {
        $request = Fixture::request();
        $key = $request->invocationRef();
        $original = $request->originalRequestDigest();
        $claim = new A\LedgerClaim($key, new D\Decimal('1'), 'worker:1', new DateTimeImmutable('2030-01-01'), new D\Decimal('2'));
        $pins = new A\InvocationPins(Fixture::binding(), Fixture::profile(), $original);
        $this->rejects(fn () => new A\InvocationRecord(
            $key,
            new D\Sha256(str_repeat('9', 64)),
            $request->fingerprint($pins->binding),
            $pins,
            A\InvocationState::Preparing,
            $claim,
        ));
        $foreign = Fixture::binding(['scope' => Fixture::scope(['tenantId' => '01JC0000000000000000000009'])]);
        $this->rejects(fn () => new A\InvocationRecord(
            $key,
            $original,
            $request->fingerprint($pins->binding),
            new A\InvocationPins($foreign, Fixture::profile(), $original),
            A\InvocationState::Preparing,
            $claim,
        ));
        self::assertSame(3, (new \ReflectionMethod(\KnowKit\Answer\Port\InvocationLedger::class, 'begin'))->getNumberOfParameters());
        $reserve = new \ReflectionMethod(\KnowKit\Answer\Port\AnswerBudget::class, 'reserve');
        self::assertSame(A\InvocationKey::class, $reserve->getParameters()[0]->getType()->getName());
    }

    private function rejects(callable $make): void
    {
        try {
            $make();
        } catch (\InvalidArgumentException|\DomainException|D\DomainFailure|\LogicException|\UnderflowException $error) {
            self::assertNotSame('', $error->getMessage());

            return;
        }
        self::fail('Invalid contract accepted');
    }
}
