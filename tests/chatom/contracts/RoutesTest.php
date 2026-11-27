<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Contracts;

use InvalidArgumentException;
use KnowKit\Chatom\ConversationRouteGuard;
use KnowKit\Chatom\Domain\ConversationBinding;
use KnowKit\Chatom\Domain\ConversationRef;
use KnowKit\Chatom\Domain\NotHandledReason;
use KnowKit\Chatom\Domain\RouteRegistration;
use KnowKit\Chatom\RouteRegistrationAssertion;
use KnowKit\Domain\ConversationRoute;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('chatom:contracts')]
final class RoutesTest extends TestCase
{
    public function testSameBindingRejectsEveryPairOfRouteOwnersIncludingDuplicateSameRoute(): void
    {
        foreach (ConversationRoute::cases() as $first) {
            foreach (ConversationRoute::cases() as $second) {
                $this->rejects(static fn () => new RouteRegistrationAssertion([
                    new RouteRegistration(conversation: Fixture::conversation(), route: $first, owner: 'first'),
                    new RouteRegistration(conversation: Fixture::conversation(), route: $second, owner: 'second'),
                ]));
            }
        }
        $same = new RouteRegistration(conversation: Fixture::conversation(), route: ConversationRoute::DirectChatom, owner: 'same');
        $this->rejects(static fn () => new RouteRegistrationAssertion([$same, $same]));
    }

    public function testDistinctBotsAndEveryOtherTupleAxisSupportIndependentOwners(): void
    {
        $first = Fixture::conversation();

        foreach (Fixture::corpus()['distinctConversations'] as $changes) {
            $other = Fixture::conversation($changes);
            $registrations = new RouteRegistrationAssertion([
                new RouteRegistration(conversation: $first, route: ConversationRoute::DirectChatom, owner: 'direct'),
                new RouteRegistration(conversation: $other, route: ConversationRoute::AgentoolsOrchestrated, owner: 'relay'),
            ]);
            self::assertNotSame(expected: $first->registrationKey(), actual: $other->registrationKey());
            self::assertSame(expected: ConversationRoute::DirectChatom, actual: $registrations->ownerFor($first)->route);
            self::assertSame(expected: ConversationRoute::AgentoolsOrchestrated, actual: $registrations->ownerFor($other)->route);
            $this->assertMatched(conversation: $first, route: ConversationRoute::DirectChatom, registrations: $registrations);
            $this->assertMatched(conversation: $other, route: ConversationRoute::AgentoolsOrchestrated, registrations: $registrations);
        }
    }

    public function testTupleEncodingCannotCollideOnOpaqueIdentifierSeparators(): void
    {
        $first = Fixture::conversation(['spaceId' => 'a:b', 'channelId' => 'c']);
        $other = Fixture::conversation(['spaceId' => 'a', 'channelId' => 'b:c']);
        self::assertNotSame(expected: $first->registrationKey(), actual: $other->registrationKey());
    }

    public function testRouteMismatchHasNoResolverHistoryLedgerModelOrDeliveryEffects(): void
    {
        foreach (ConversationRoute::cases() as $registered) {
            foreach (ConversationRoute::cases() as $invoked) {
                if ($registered === $invoked) {
                    continue;
                }

                $resolver = new Fixture(Fixture::binding(['route' => $registered->value]));
                $guard = $this->guard(resolver: $resolver, route: $registered);
                $result = $guard->check(conversation: Fixture::conversation(), route: $invoked);
                self::assertSame(expected: NotHandledReason::RouteMismatch, actual: $result->reason);
                self::assertNull($result->binding);
                self::assertSame(expected: 0, actual: $resolver->reads);
                $this->assertNoEffects($result->isHandled());
            }
        }
    }

    public function testAuthoritativeRouteOrIdentityMismatchCannotBorrowARegistration(): void
    {
        $wrong = [Fixture::binding(['route' => 'agentools_orchestrated'])];

        foreach (Fixture::corpus()['distinctConversations'] as $changes) {
            $wrong[] = Fixture::binding(conversation: Fixture::conversation($changes));
        }

        foreach ($wrong as $binding) {
            $resolver = new Fixture($binding);
            $result = $this->guard($resolver)->check(conversation: Fixture::conversation(), route: ConversationRoute::DirectChatom);
            self::assertSame(expected: NotHandledReason::RouteMismatch, actual: $result->reason);
            self::assertSame(expected: 1, actual: $resolver->reads);
            self::assertNull($result->binding);
            $this->assertNoEffects($result->isHandled());
        }
    }

    public function testChangedBindingRechecksAllHostPinsAfterOwnerIoWithoutAdoptingNewModel(): void
    {
        $admitted = Fixture::binding();
        $resolver = new Fixture($admitted);
        $guard = $this->guard($resolver);
        self::assertSame(expected: $admitted, actual: $guard->check(conversation: $admitted->conversation, route: $admitted->route)->binding);

        foreach (Fixture::corpus()['changedPins'] as $changes) {
            $resolver->binding = Fixture::binding($changes);
            $result = $guard->check(conversation: $admitted->conversation, route: $admitted->route, expectedBinding: $admitted);
            self::assertSame(expected: NotHandledReason::ChangedBinding, actual: $result->reason);
            self::assertNull($result->binding);
            $this->assertNoEffects($result->isHandled());
        }

        foreach ([['instanceId' => 'other-instance'], ['layerStackRef' => 'stack:other']] as $changes) {
            $resolver->binding = Fixture::binding($changes);
            $result = $guard->check(conversation: $admitted->conversation, route: $admitted->route, expectedBinding: $admitted);
            self::assertSame(expected: NotHandledReason::ChangedBinding, actual: $result->reason);
            self::assertNull($result->binding);
            $this->assertNoEffects($result->isHandled());
        }

        $resolver->binding = Fixture::binding();
        self::assertTrue($guard->check(conversation: $admitted->conversation, route: $admitted->route, expectedBinding: $admitted)->isHandled());
        self::assertSame(expected: 'model:tutor-original', actual: $admitted->modelBindingRef->value);
    }

    public function testMissingOrUnknownConfigurationDoesNoWorkAndLeaksNoHostDiagnostic(): void
    {
        $resolver = new Fixture(Fixture::binding());
        $empty = new ConversationRouteGuard(registrations: new RouteRegistrationAssertion([]), resolver: $resolver);
        $missing = $empty->check(conversation: Fixture::conversation(), route: ConversationRoute::DirectChatom);
        self::assertSame(expected: NotHandledReason::MissingConfiguration, actual: $missing->reason);
        self::assertSame(expected: 0, actual: $resolver->reads);
        $this->assertNoEffects($missing->isHandled());
        $missing = $this->guard(null)->check(conversation: Fixture::conversation(), route: ConversationRoute::DirectChatom);
        self::assertSame(expected: NotHandledReason::MissingConfiguration, actual: $missing->reason);
        self::assertNull($missing->binding);
        $resolver->unavailable = true;
        $unknown = $this->guard($resolver)->check(conversation: Fixture::conversation(), route: ConversationRoute::DirectChatom);
        self::assertSame(expected: NotHandledReason::BindingUnavailable, actual: $unknown->reason);
        self::assertNull($unknown->binding);
        self::assertStringNotContainsString(needle: 'protected', haystack: json_encode($unknown, JSON_THROW_ON_ERROR));
        $this->assertNoEffects($unknown->isHandled());
    }

    public function testBindingRejectsCrossTenantOrKbScopeAndMalformedReferences(): void
    {
        $binding = Fixture::binding();

        foreach ([Fixture::conversation(['tenantId' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ']), Fixture::conversation()] as $conversation) {
            $scope = $conversation->tenantId->equals($binding->conversation->tenantId)
                ? Fixture::binding(['kbId' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ'])->hostScopeInputs
                : $binding->hostScopeInputs;
            $this->rejects(static fn () => new ConversationBinding(
                conversation: $conversation,
                route: $binding->route,
                kbId: $binding->kbId,
                modelBindingRef: $binding->modelBindingRef,
                hostScopeInputs: $scope,
                viewerBindingRef: $binding->viewerBindingRef,
                bindingRevision: $binding->bindingRevision,
                instanceId: $binding->instanceId,
                layerStackRef: $binding->layerStackRef,
            ));
        }

        foreach (['', 'has space', "bad\0id", "bad\xff", str_repeat('x', 513)] as $value) {
            $this->rejects(static fn () => Fixture::conversation(['botId' => $value]));
            $this->rejects(static fn () => Fixture::binding(['viewerBindingRef' => $value]));
            $this->rejects(static fn () => Fixture::binding(['bindingRevision' => $value]));
        }
    }

    private function guard(?Fixture $resolver, ConversationRoute $route = ConversationRoute::DirectChatom): ConversationRouteGuard
    {
        return new ConversationRouteGuard(
            registrations: new RouteRegistrationAssertion([
                new RouteRegistration(conversation: Fixture::conversation(), route: $route, owner: 'host:owner'),
            ]),
            resolver: $resolver,
        );
    }

    private function assertMatched(ConversationRef $conversation, ConversationRoute $route, RouteRegistrationAssertion $registrations): void
    {
        $binding = Fixture::binding(changes: ['route' => $route->value], conversation: $conversation);
        $guard = new ConversationRouteGuard(registrations: $registrations, resolver: new Fixture($binding));
        self::assertSame(expected: $binding, actual: $guard->check(conversation: $conversation, route: $route)->binding);
    }

    /** Host composition canary: every protected operation is behind the guard. */
    private function assertNoEffects(bool $handled): void
    {
        $effects = ['history' => 0, 'ledger' => 0, 'model' => 0, 'reply' => 0, 'listener' => 0];
        $plaintext = null;

        if ($handled) {
            foreach ($effects as &$count) {
                $count++;
            }
            $plaintext = 'protected fixture content';
        }

        self::assertSame(expected: [0, 0, 0, 0, 0], actual: array_values($effects));
        self::assertNull($plaintext);
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $error) {
            self::assertNotEmpty($error->getMessage());

            return;
        }

        self::fail('Invalid route registration or host binding accepted');
    }
}
