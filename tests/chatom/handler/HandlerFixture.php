<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Handler;

use Chatom\Actors\Data\ActorRef;
use Chatom\Agents\Data\AgentInvocation;
use Chatom\Agents\Data\AgentMessageSnapshot;
use Chatom\Channels\Data\ChannelId;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Contracts\ViewerMessageReader;
use Chatom\Messages\Data\MessageId;
use Chatom\Messages\Data\MessageView;
use Chatom\Messages\Data\ViewerMessageHistory;
use Chatom\Messages\Enums\MessageStatus;
use Chatom\Messages\Enums\MessageType;
use Chatom\Spaces\Data\SpaceId;
use Chatom\Spaces\Data\TenantKey;
use Chatom\Support\Data\Page;
use Closure;
use KnowKit\Answer\Application as App;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\GroundedAnswering;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Chatom\ChatomHistoryHydrator;
use KnowKit\Chatom\ChatomReplyBoundary;
use KnowKit\Chatom\ChatomReplyMapper;
use KnowKit\Chatom\ConversationRouteGuard;
use KnowKit\Chatom\Domain\AuthorizedHistory;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Domain\HandlerConfiguration;
use KnowKit\Chatom\Domain\LayerStackRef;
use KnowKit\Chatom\Domain\RouteRegistration;
use KnowKit\Chatom\KnowKitDurableAgentHandler;
use KnowKit\Chatom\Port\ConversationAnswerFactory;
use KnowKit\Chatom\Port\ConversationAudience;
use KnowKit\Chatom\Port\ConversationBindingResolver;
use KnowKit\Chatom\RouteRegistrationAssertion;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\InstanceId;
use KnowKit\Tests\Answer\Contracts\Fixture as AnswerFixture;
use KnowKit\Tests\Answer\Grounding\GroundingFixture;
use PHPUnit\Framework\Assert;
use RuntimeException;

/** Synthetic host, real native DTOs and actual answer application/ledger contract. */
final class HandlerFixture implements ConversationAudience, ConversationBindingResolver, ConversationAnswerFactory, ViewerMessageReader
{
    public GroundingFixture $answer;
    public ConversationBinding $binding;
    public array $messages;
    public array $denied = [];
    public int $reads = 0;
    public int $checks = 0;
    public ?Closure $afterRead = null;
    public ?Closure $changeAttestation = null;
    public ?Closure $changeOutcome = null;
    public ?AuthorizedHistory $lastHistory = null;
    public ?A\DependencyManifest $lastDependencies = null;
    public ?ViewerMessageHistory $lastQuery = null;
    public ?InstanceId $lastInstanceId = null;
    public ?LayerStackRef $lastLayerStackRef = null;

    public function __construct()
    {
        $this->answer = new GroundingFixture();
        $scope = AnswerFixture::scope();
        $this->binding = new ConversationBinding(
            conversation: new ConversationRef(
                tenantId: $scope->tenantId,
                spaceId: 'school',
                channelId: 'lesson',
                botId: 'bot:tutor',
                bindingId: 'lesson:tutor',
            ),
            route: ConversationRoute::DirectChatom,
            kbId: $scope->kbId,
            modelBindingRef: $this->answer->binding->requestedRef,
            hostScopeInputs: $scope,
            viewerBindingRef: 'learner:class:lesson:consent:1',
            bindingRevision: 'binding:1',
            instanceId: InstanceId::fromString('default'),
            layerStackRef: new LayerStackRef('stack:1'),
        );
        $this->messages = [
            $this->message(id: 'trigger', position: 100, text: self::corpus()['question']),
            $this->message(id: 'prior', position: 99, text: self::corpus()['history'], sender: ActorRef::of(type: 'bot', id: 'tutor')),
            $this->message(id: 'first', position: 98, text: 'Earlier learner turn'),
        ];
    }

    public static function corpus(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/chatom/history/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public function invocation(string $snapshotText = 'UNAUTHORIZED RAW SNAPSHOT'): AgentInvocation
    {
        return new AgentInvocation(
            tenant: TenantKey::from($this->binding->conversation->tenantId->value),
            space: SpaceId::from('school'),
            channel: ChannelId::from('lesson'),
            message: new AgentMessageSnapshot(
                id: MessageId::from('trigger'),
                sender: ActorRef::of(type: 'user', id: 'learner'),
                content: MessageDocument::fromPlainText($snapshotText),
                position: 100,
                revision: $this->triggerRevision(),
            ),
            agent: ActorRef::of(type: 'bot', id: 'tutor'),
            handlerKey: 'knowkit.lesson',
            handlerVersion: 1,
            invocationKey: self::corpus()['invocationKey'],
        );
    }

    private function triggerRevision(): int
    {
        $index = array_search('trigger', array_map(static fn (MessageView $message): string => $message->id->value, $this->messages), true);

        return is_int($index) ? $this->messages[$index]->revision : 1;
    }

    public function message(string $id, int $position, string $text, ?ActorRef $sender = null, int $revision = 1): MessageView
    {
        return new MessageView(
            id: MessageId::from($id),
            channel: ChannelId::from('lesson'),
            sender: $sender ?? ActorRef::of(type: 'user', id: 'learner'),
            type: MessageType::Regular,
            status: MessageStatus::Sent,
            content: MessageDocument::fromPlainText($text),
            body: $text,
            silent: false,
            replyTo: null,
            parent: null,
            reactions: [],
            clientMessageId: null,
            position: $position,
            revision: $revision,
            extraData: null,
            nodes: [],
            contributions: [],
            extra: [],
            editedAt: null,
            createdAt: $this->answer->date(),
            updatedAt: $this->answer->date(),
        );
    }

    public function handler(ChatomReplyBoundary $boundary, ?A\AnswerLimits $limits = null): KnowKitDurableAgentHandler
    {
        $registrations = new RouteRegistrationAssertion([
            new RouteRegistration(conversation: $this->binding->conversation, route: ConversationRoute::DirectChatom, owner: 'knowkit'),
        ]);

        return new KnowKitDurableAgentHandler(
            configuration: new HandlerConfiguration(
                conversation: $this->binding->conversation,
                handlerKey: 'knowkit.lesson',
                handlerVersion: 1,
                retrievalProfile: AnswerFixture::profile(),
                limits: $limits ?? new A\AnswerLimits(),
            ),
            routes: new ConversationRouteGuard(registrations: $registrations, resolver: $this),
            history: new ChatomHistoryHydrator(reader: $this, audience: $this, clock: $this->answer),
            audience: $this,
            answers: $this,
            ledger: $this->answer->ledger,
            mapper: new ChatomReplyMapper(),
            boundary: $boundary,
            cancellation: $this->answer->token(),
        );
    }

    public function resolve(ConversationRef $conversation): ConversationBinding
    {
        return $this->binding;
    }

    public function history(ViewerMessageHistory $query): Page
    {
        $this->reads++;
        $this->lastQuery = $query;
        Assert::assertSame('user:learner', (string) $query->viewer);
        $page = new Page($this->messages);
        if ($this->afterRead !== null) {
            ($this->afterRead)();
        }

        return $page;
    }

    public function visibilityOf(SpaceId $space, ActorRef $viewer, array $references): array
    {
        throw new RuntimeException('Classification is not history authorization');
    }

    public function viewer(ConversationBinding $binding, AgentInvocation $invocation): ActorRef
    {
        $this->assertFacts();

        return ActorRef::of(type: 'user', id: 'learner');
    }

    public function attest(ConversationBinding $binding, AgentInvocation $invocation, AuthorizedHistory $history): A\HistoryReadAttestation
    {
        $this->assertCurrent(binding: $binding, invocation: $invocation, history: $history, dependencies: null);
        $this->lastHistory = $history;

        $attestation = new A\HistoryReadAttestation(
            scope: $binding->hostScopeInputs,
            orderedTurnsDigest: $history->priorTurns->digest(),
            ref: $history->manifestRef(),
            expiresAt: $this->answer->date()->modify('+30 seconds'),
        );

        return $this->changeAttestation === null ? $attestation : ($this->changeAttestation)($attestation);
    }

    public function assertCurrent(ConversationBinding $binding, AgentInvocation $invocation, AuthorizedHistory $history, ?A\DependencyManifest $dependencies): void
    {
        $this->checks++;
        if ($dependencies !== null) {
            $this->lastDependencies = $dependencies;
        }
        $this->assertFacts();
        if (!$binding->equals($this->binding)) {
            throw new RuntimeException('Binding changed');
        }
        foreach ($history->messages as $message) {
            $current = array_values(array_filter($this->messages, static fn (MessageView $m): bool => $m->id->value === $message->id->value));
            if (count($current) !== 1 || $current[0] != $message) {
                throw new RuntimeException('History revision changed');
            }
        }
        foreach ($dependencies?->evidence ?? [] as $citation) {
            Assert::assertSame($this->answer->citation()->canonical(), $citation->canonical());
        }
    }

    private function assertFacts(): void
    {
        if ($this->denied !== []) {
            throw new RuntimeException('Protected host diagnostic: ' . implode(',', $this->denied));
        }
    }

    public function changeBinding(): void
    {
        $old = $this->binding;
        $this->binding = new ConversationBinding(
            conversation: $old->conversation,
            route: $old->route,
            kbId: $old->kbId,
            modelBindingRef: $old->modelBindingRef,
            hostScopeInputs: $old->hostScopeInputs,
            viewerBindingRef: $old->viewerBindingRef,
            bindingRevision: 'binding:2',
            instanceId: $old->instanceId,
            layerStackRef: $old->layerStackRef,
        );
    }

    public function create(
        InvocationAuthorization $authorization,
        InstanceId $instanceId,
        ?LayerStackRef $layerStackRef = null,
    ): GroundedAnswering {
        $this->lastInstanceId = $instanceId;
        $this->lastLayerStackRef = $layerStackRef;
        $f = $this->answer;

        $service = new App\GroundedAnswerService(
            admission: new App\InvocationAdmission(ledger: $f->ledger, bindings: $f),
            ledger: $f->ledger,
            replay: new App\InvocationReplay(ledger: $f->ledger, payloads: $f, authorization: $authorization, validator: $f->validator),
            evidence: new App\GroundingEvidenceReader(retrieval: $f, disclosure: $f, ids: $f, validator: $f->validator, profile: AnswerFixture::profile()),
            composer: new App\GroundingPromptComposer(),
            authorization: $authorization,
            egress: new App\CurrentModelEgress(policy: $f, clock: $f, validator: $f->validator),
            budget: $f,
            transport: $f,
            mapper: new App\AnswerOutcomeMapper(citations: new App\CitationValidator($f->validator)),
            settlement: new App\AnswerSettlement(payloads: $f, budget: $f, clock: $f),
            clock: $f,
            mode: ProfileMode::Test,
        );

        return new class ($service, $this) implements GroundedAnswering {
            public function __construct(
                private GroundedAnswering $service,
                private HandlerFixture $fixture,
            ) {}

            public function answer(A\AnswerRequest $request, A\CancellationToken $cancellation): A\AnswerOutcome
            {
                $outcome = $this->service->answer(request: $request, cancellation: $cancellation);

                return $this->fixture->changeOutcome === null ? $outcome : ($this->fixture->changeOutcome)($outcome);
            }
        };
    }
}
