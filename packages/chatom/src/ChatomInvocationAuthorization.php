<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use Chatom\Agents\Data\AgentInvocation;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\InvocationAuthorization;
use KnowKit\Chatom\Domain\AuthorizedHistory;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationDenied;
use KnowKit\Chatom\Port\ConversationAudience;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\Scope;
use Throwable;

/** Used at the answer owner's final send and replay gates, not just handler entry. */
final readonly class ChatomInvocationAuthorization implements InvocationAuthorization
{
    public function __construct(
        private ConversationRouteGuard $routes,
        private ConversationAudience $audience,
        private ConversationBinding $binding,
        private AgentInvocation $invocation,
        private AuthorizedHistory $history,
    ) {}

    public function beforeSend(Scope $scope, A\ModelCallIdentity $identity): ?A\AnswerOutcome
    {
        return $this->decision(scope: $scope, ref: $identity->invocationRef, dependencies: $identity->dependencies);
    }

    public function beforeReplay(Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->decision(scope: $scope, ref: $record->invocationRef, dependencies: $record->dependencies);
    }

    public function beforeResume(Scope $scope, A\InvocationRecord $record): ?A\AnswerOutcome
    {
        return $this->beforeReplay(scope: $scope, record: $record);
    }

    public function assertCurrent(?A\DependencyManifest $dependencies = null): void
    {
        try {
            $this->audience->assertCurrent(
                binding: $this->binding,
                invocation: $this->invocation,
                history: $this->history,
                dependencies: $dependencies,
            );
            $result = $this->routes->check(
                conversation: $this->binding->conversation,
                route: ConversationRoute::DirectChatom,
                expectedBinding: $this->binding,
            );
            if (!$result->isHandled()) {
                throw new ConversationDenied();
            }
        } catch (Throwable) {
            throw new ConversationDenied();
        }
    }

    private function decision(Scope $scope, A\InvocationRef $ref, ?A\DependencyManifest $dependencies): ?A\AnswerOutcome
    {
        try {
            A\Guard::sameScope($this->binding->hostScopeInputs, $scope);
            if ($ref->key->value !== $this->invocation->invocationKey) {
                throw new ConversationDenied();
            }
            $scope->assertTenant($ref->tenantId);
            if ($dependencies === null || $dependencies->history === []) {
                throw new ConversationDenied();
            }
            foreach ($dependencies->history as $dependency) {
                if ($dependency->ref !== $this->history->manifestRef()) {
                    throw new ConversationDenied();
                }
            }
            $this->assertCurrent($dependencies);

            return null;
        } catch (Throwable) {
            return new A\AnswerOutcome(
                code: A\OutcomeCode::PolicyUnknown,
                invocationRef: $ref,
                certainty: A\DispatchCertainty::KnownNotSent,
            );
        }
    }
}
