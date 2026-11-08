<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use KnowKit\Answer\Domain\OutcomeCode;
use KnowKit\Answer\Adapter\OpenAiCompatibleTransport;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Chatom\ConversationRouteGuard;
use KnowKit\Chatom\Domain\RouteRegistration;
use KnowKit\Chatom\RouteRegistrationAssertion;
use KnowKit\Domain\ConversationRoute;
use KnowKit\Domain\DomainFailure;
use KnowKit\Tests\Answer\Grounding\GroundingFixture;
use KnowKit\Tests\Chatom\Contracts\Fixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Http\Client\ClientInterface;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/contracts/Fixture.php';

#[Group('chatom:qualification')]
final class FixtureTierTest extends TestCase
{
    public function testOneBindingRejectsBothRoutesButDistinctBindingsCoexist(): void
    {
        $conversation = Fixture::conversation();
        $direct = new RouteRegistration(conversation: $conversation, route: ConversationRoute::DirectChatom, owner: 'knowkit');
        $orchestrated = new RouteRegistration(conversation: $conversation, route: ConversationRoute::AgentoolsOrchestrated, owner: 'agentools');
        foreach ([[$direct, $orchestrated], [$orchestrated, $direct], [$direct, $direct]] as $invalid) {
            try {
                new RouteRegistrationAssertion($invalid);
                self::fail('One binding must not activate two owners');
            } catch (\InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        $second = Fixture::conversation(['bindingId' => 'other-binding', 'botId' => 'bot:other']);
        $registrations = new RouteRegistrationAssertion([
            $direct,
            new RouteRegistration(conversation: $second, route: ConversationRoute::AgentoolsOrchestrated, owner: 'agentools'),
        ]);
        foreach ([[$conversation, ConversationRoute::DirectChatom], [$second, ConversationRoute::AgentoolsOrchestrated]] as [$ref, $route]) {
            $resolver = new Fixture(Fixture::binding(changes: ['route' => $route->value], conversation: $ref));
            $guard = new ConversationRouteGuard(registrations: $registrations, resolver: $resolver);
            self::assertNotNull($guard->check(conversation: $ref, route: $route)->binding);
            $wrong = $route === ConversationRoute::DirectChatom ? ConversationRoute::AgentoolsOrchestrated : ConversationRoute::DirectChatom;
            self::assertNull($guard->check(conversation: $ref, route: $wrong)->binding);
        }
    }

    public function testFixtureCannotBecomeProductionAndDeniedQuestionOrHistorySendsNoBytes(): void
    {
        $fixture = new GroundingFixture();
        $fixture->testTransport = true;
        try {
            $fixture->service(mode: ProfileMode::Production);
            self::fail('Fixture transport accepted in production');
        } catch (DomainFailure $error) {
            self::assertSame('test_transport_forbidden', $error->errorCode);
        }
        self::assertSame(0, $fixture->sends);
        self::assertSame(0, $fixture->outboundBytes);
        foreach (['QUESTION', 'HISTORY'] as $category) {
            $fixture = new GroundingFixture();
            $fixture->egressDeny = $category;
            $outcome = $fixture->service()->answer(request: $fixture->request(), cancellation: $fixture->token());
            self::assertSame(OutcomeCode::EgressDenied, $outcome->code);
            self::assertNull($outcome->answeredContent());
            self::assertSame(0, $fixture->sends);
            self::assertSame(0, $fixture->outboundBytes);
        }
    }

    public function testMissingProductionConfigurationDoesNotProbeInjectedClient(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('sendRequest');
        $fixture = new GroundingFixture();
        $transport = new OpenAiCompatibleTransport(clock: $fixture, client: $client);
        $readiness = $transport->readiness();
        self::assertFalse($readiness->configured());
        $outcome = $readiness->outcome($fixture->request()->invocationRef());
        self::assertSame(OutcomeCode::Unavailable, $outcome->code);
        self::assertNull($outcome->answeredContent());
        self::assertSame(0, $fixture->sends);
        self::assertSame(0, $fixture->outboundBytes);
    }

    public function testManifestRequiresEveryExecutedCaseAndRejectsMissingSkippedZeroOrChangedPins(): void
    {
        $qa = new Qa(dirname(__DIR__, 3));
        $manifest = $qa->json('tests/fixtures/check-groups/chatom-qualification.json');
        $qa->fixtures($manifest);
        self::assertSame(NativeHost::matrix()['requiredCaseIds'], $manifest['requiredCaseIds']);
        $cases = [];
        foreach ($manifest['testCaseMap'] as $methods) {
            foreach ($methods as $method) {
                [$class, $name] = explode('::', $method);
                $name = explode(' with data set ', $name)[0];
                self::assertTrue(method_exists(object_or_class: $class, method: $name));
                $cases[$method] = ['id' => $method, 'status' => 'passed'];
            }
        }
        self::assertCount(7, Qa::validateCases(cases: array_values($cases), manifest: $manifest));
        foreach (['zero', 'missing', 'skipped', 'failed'] as $mutation) {
            $changed = array_values($cases);
            if ($mutation === 'zero') {
                $changed = [];
            } elseif ($mutation === 'missing') {
                array_shift($changed);
            } else {
                $changed[0]['status'] = $mutation;
            }
            try {
                Qa::validateCases(cases: $changed, manifest: $manifest);
                self::fail('Inventory accepted ' . $mutation);
            } catch (RuntimeException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        $manifest['fixtures'][0]['sha256'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $qa->fixtures($manifest);
    }
}
