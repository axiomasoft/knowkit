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
            if ($dependencies !== null) {
                $this->requireHistory($dependencies);
            }
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

    private function requireHistory(A\DependencyManifest $dependencies): void
    {
        $expected = [];
        foreach ($this->history->priorTurns->turns as $index => $turn) {
            $expected[] = ['revision' => (string) $index, 'digest' => hash('sha256', $turn->text)];
        }
        if ($expected === []) {
            $expected[] = ['revision' => 'empty', 'digest' => hash('sha256', '')];
        }
        if (count($dependencies->history) !== count($expected)) {
            throw new ConversationDenied();
        }
        $ref = $this->history->manifestRef();
        $tenant = $this->binding->hostScopeInputs->tenantId;
        $index = 0;
        foreach ($dependencies->history as $dependency) {
            if (!$dependency->tenantId->equals($tenant) || $dependency->ref !== $ref
                || $dependency->revision !== $expected[$index]['revision']
                || $dependency->contentDigest->value !== $expected[$index]['digest']) {
                throw new ConversationDenied();
            }
            $index++;
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
            if ($dependencies === null) {
                throw new ConversationDenied();
            }
            $this->requireHistory($dependencies);
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
