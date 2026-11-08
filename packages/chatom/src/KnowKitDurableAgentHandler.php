<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Agents\Contracts\DurableAgentHandler;
use Chatom\Agents\Contracts\DurableAgentReply;
use Chatom\Agents\Data\AgentInvocation;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationLedger;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationDenied;
use KnowKit\Chatom\Domain\HandlerConfiguration;
use KnowKit\Chatom\Port\ConversationAnswerFactory;
use KnowKit\Chatom\Port\ConversationAudience;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Sha256;

final readonly class KnowKitDurableAgentHandler implements DurableAgentHandler
{
    public function __construct(
        private HandlerConfiguration $configuration,
        private ConversationRouteGuard $routes,
        private ChatomHistoryHydrator $history,
        private ConversationAudience $audience,
        private ConversationAnswerFactory $answers,
        private InvocationLedger $ledger,
        private ChatomReplyMapper $mapper,
        private ChatomReplyBoundary $boundary,
        private A\CancellationToken $cancellation,
    ) {}

    public function streaming(): bool
    {
        return false;
    }

    public function handle(AgentInvocation $invocation, DurableAgentReply $reply): void
    {
        if (!$this->configuration->matches($invocation)) {
            return;
        }
        $binding = $this->routes->check(
            conversation: $this->configuration->conversation,
            route: ConversationRoute::DirectChatom,
        )->binding;
        if ($binding === null) {
            return;
        }
        try {
            $this->respond(binding: $binding, invocation: $invocation, reply: $reply);
        } catch (ConversationDenied) {
            return;
        }
    }

    private function respond(ConversationBinding $binding, AgentInvocation $invocation, DurableAgentReply $reply): void
    {
        $history = $this->history->hydrate(binding: $binding, invocation: $invocation, limits: $this->configuration->limits);
        $authorization = new ChatomInvocationAuthorization(
            routes: $this->routes,
            audience: $this->audience,
            binding: $binding,
            invocation: $invocation,
            history: $history,
        );
        $authorization->assertCurrent();
        $request = new A\AnswerRequest(
            scope: $binding->hostScopeInputs,
            question: $history->trigger->body,
            priorTurns: $history->priorTurns,
            modelBindingRef: $binding->modelBindingRef,
            retrievalProfileRef: $this->configuration->retrievalProfile,
            invocationKey: new A\InvocationKey($invocation->invocationKey),
            limits: $this->configuration->limits,
        );
        $outcome = $this->answers->create($authorization)->answer(request: $request, cancellation: $this->cancellation);
        $content = $outcome->answeredContent();
        if ($content === null || $outcome->invocationRef != $request->invocationRef()) {
            return;
        }
        $this->publish(invocation: $invocation, reply: $reply, request: $request, outcome: $outcome, authorization: $authorization);
    }

    private function publish(
        AgentInvocation $invocation,
        DurableAgentReply $reply,
        A\AnswerRequest $request,
        A\AnswerOutcome $outcome,
        ChatomInvocationAuthorization $authorization,
    ): void {
        $record = $this->ledger->find($request->invocationRef());
        if ($record === null || $record->state !== A\InvocationState::Completed || $record->dependencies === null) {
            throw new ConversationDenied();
        }
        $record->requireOriginal($request);
        $digest = new Sha256(hash('sha256', Jcs::canonicalize($outcome->toWire())));
        if ($record->responsePayloadHash != $digest) {
            throw new DomainFailure(outcome: Outcome::Incompatible, errorCode: 'invocation_conflict');
        }
        $dependencies = $record->dependencies;
        $authorization->assertCurrent($dependencies);
        $document = $this->mapper->map($outcome->answeredContent());
        $this->boundary->send(
            invocation: $invocation,
            reply: $reply,
            content: $document,
            guard: static fn () => $authorization->assertCurrent($dependencies),
        );
    }
}
