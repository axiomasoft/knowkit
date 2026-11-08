<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Agents\Contracts\DurableAgentHandler;
use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Agents\Data\AgentInvocation;
use Chatom\Actors\Data\ActorRef;
use Chatom\Messages\Contracts\ViewerMessageReader;
use Chatom\Messages\Data\ViewerMessageHistory;
use Closure;
use KnowKit\Chatom\ChatomHistoryHydrator;
use KnowKit\Chatom\ChatomReplyBoundary;
use KnowKit\Chatom\ChatomReplyMapper;
use KnowKit\Chatom\ConversationRouteGuard;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Domain\HandlerConfiguration;
use KnowKit\Chatom\Domain\RouteRegistration;
use KnowKit\Chatom\KnowKitDurableAgentHandler;
use KnowKit\Chatom\RouteRegistrationAssertion;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Chatom\Handler\HandlerFixture;

/** Synthetic host authority/model, installed native history/reply/storage. */
final class InstalledHandler implements DurableAgentHandler
{
    public array $hosts = [];
    public array $invocations = [];
    public array $nativeReplies = [];
    public array $errors = [];
    public bool $loseAck = false;
    public ?Closure $revokeAtWrite = null;
    public ?Closure $beforeHandle = null;

    public function __construct(
        private readonly ViewerMessageReader $reader,
        private readonly ChatomReplyBoundary $boundary,
    ) {}

    public function streaming(): bool
    {
        return false;
    }

    public function handle(AgentInvocation $invocation, DurableAgentReply $reply): void
    {
        $this->invocations[] = $invocation;
        $this->nativeReplies[] = $reply::class;
        $host = $this->hosts[$invocation->agent->id] ??= $this->host($invocation);
        if ($this->beforeHandle !== null) {
            ($this->beforeHandle)($host, $invocation);
        }
        $this->refreshHistory(host: $host, invocation: $invocation);
        $handler = $this->handler(host: $host, invocation: $invocation);
        $loseAck = $this->loseAck;
        $this->loseAck = false;
        try {
            $handler->handle(invocation: $invocation, reply: $loseAck ? new AcknowledgementLoss($reply) : $reply);
        } catch (\Throwable $error) {
            $this->errors[] = $error;
            throw $error;
        }
    }

    private function refreshHistory(HandlerFixture $host, AgentInvocation $invocation): void
    {
        if ($host->binding->route !== ConversationRoute::DirectChatom) {
            return;
        }
        $host->messages = $this->reader->history(new ViewerMessageHistory(
            space: $invocation->space,
            channel: $invocation->channel,
            viewer: ActorRef::of(type: 'user', id: 'learner'),
            limit: 100,
        ))->items;
    }

    private function handler(HandlerFixture $host, AgentInvocation $invocation): KnowKitDurableAgentHandler
    {
        $registrations = new RouteRegistrationAssertion([
            new RouteRegistration(conversation: $host->binding->conversation, route: $host->binding->route, owner: 'qualification'),
        ]);
        return new KnowKitDurableAgentHandler(
            configuration: new HandlerConfiguration(
                conversation: $host->binding->conversation,
                handlerKey: $invocation->handlerKey,
                handlerVersion: $invocation->handlerVersion,
                retrievalProfile: Fixture::profile(),
            ),
            routes: new ConversationRouteGuard(registrations: $registrations, resolver: $host),
            history: new ChatomHistoryHydrator(reader: $this->reader, audience: $host, clock: $host->answer),
            audience: $host,
            answers: $host,
            ledger: $host->answer->ledger,
            mapper: new ChatomReplyMapper(),
            boundary: $this->boundary,
            cancellation: $host->answer->token(),
        );
    }

    public function host(AgentInvocation $invocation): HandlerFixture
    {
        $host = new HandlerFixture();
        $old = $host->binding;
        $host->binding = new ConversationBinding(
            conversation: new ConversationRef(
                tenantId: $old->conversation->tenantId,
                spaceId: $invocation->space->value,
                channelId: $invocation->channel->value,
                botId: (string) $invocation->agent,
                bindingId: 'lesson:' . $invocation->agent->id,
            ),
            route: ConversationRoute::DirectChatom,
            kbId: $old->kbId,
            modelBindingRef: $old->modelBindingRef,
            hostScopeInputs: $old->hostScopeInputs,
            viewerBindingRef: $old->viewerBindingRef,
            bindingRevision: $old->bindingRevision,
        );

        return $host;
    }
}
