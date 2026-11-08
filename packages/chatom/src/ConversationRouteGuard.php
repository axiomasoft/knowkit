<?php

declare(strict_types=1);

namespace KnowKit\Chatom;

use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Domain\NotHandledReason;
use KnowKit\Chatom\Domain\RouteGuardResult;
use KnowKit\Chatom\Port\ConversationBindingResolver;
use KnowKit\Domain\ConversationRoute;
use Throwable;

/** No history, transport, ledger, delivery or registration effects occur in this guard. */
final readonly class ConversationRouteGuard
{
    public function __construct(
        private RouteRegistrationAssertion $registrations,
        private ?ConversationBindingResolver $resolver,
    ) {}

    public function check(
        ConversationRef $conversation,
        ConversationRoute $route,
        ?ConversationBinding $expectedBinding = null,
    ): RouteGuardResult {
        $owner = $this->registrations->ownerFor($conversation);

        if ($owner === null || $this->resolver === null) {
            return RouteGuardResult::notHandled(NotHandledReason::MissingConfiguration);
        }

        if ($owner->route !== $route) {
            return RouteGuardResult::notHandled(NotHandledReason::RouteMismatch);
        }

        try {
            $current = $this->resolver->resolve($conversation);
        } catch (Throwable) {
            return RouteGuardResult::notHandled(NotHandledReason::BindingUnavailable);
        }

        return $this->compare(conversation: $conversation, route: $route, current: $current, expected: $expectedBinding);
    }

    private function compare(
        ConversationRef $conversation,
        ConversationRoute $route,
        ConversationBinding $current,
        ?ConversationBinding $expected,
    ): RouteGuardResult {
        if (!$current->conversation->equals($conversation) || $current->route !== $route) {
            return RouteGuardResult::notHandled(NotHandledReason::RouteMismatch);
        }

        if ($expected !== null && !$expected->equals($current)) {
            return RouteGuardResult::notHandled(NotHandledReason::ChangedBinding);
        }

        return RouteGuardResult::matched($current);
    }
}
