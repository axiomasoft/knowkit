<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Transport;

use KnowKit\Answer\Adapter\OpenAiCompatibleTransport;
use KnowKit\Answer\Adapter\Psr18 as H;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedHttpClient;
use KnowKit\Domain as D;
use KnowKit\Tests\Answer\Contracts\Fixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:transport')]
final class TransportTest extends TestCase
{
    public function testExactWireUsesPinnedEndpointModelSettingsAndHostCredentialOnce(): void
    {
        $request = TransportFixture::request();
        $f = new TransportFixture([static function (RequestInterface $http) use ($request): Response {
            self::assertSame('POST', $http->getMethod());
            self::assertSame(TransportFixture::corpus()['endpoint'], (string) $http->getUri());
            self::assertSame('application/json', $http->getHeaderLine('Accept'));
            self::assertSame('application/json', $http->getHeaderLine('Content-Type'));
            self::assertSame('Bearer ' . TransportFixture::canary(), $http->getHeaderLine('Authorization'));
            self::assertSame($request->bytes, (string) $http->getBody());
            self::assertSame(TransportFixture::corpus()['exactWire'], (string) $http->getBody());

            return TransportFixture::response();
        }]);
        $result = $f->transport->send($request, Fixture::cancellation());
        self::assertTrue($result->complete);
        self::assertSame(A\DispatchCertainty::Sent, $result->certainty);
        self::assertFalse($result->fixture);
        self::assertSame('Bounded synthetic answer.', $result->text);
        self::assertSame('chat-model', $result->observedModelRef);
        self::assertSame(12, $result->usage->inputTokens);
        self::assertSame(4, $result->usage->outputTokens);
        self::assertSame(1, $f->http->sendCount());
        self::assertSame(strlen($request->bytes), $f->http->outboundBytes());
        self::assertSame(1, $f->credentials->calls);
        self::assertEquals($request->identity->binding, $f->credentials->observed);
        self::assertSame(1, $f->policy->calls);
        self::assertLessThanOrEqual(5000, $f->policy->limits->connectMilliseconds);
        self::assertGreaterThan(0, $f->policy->limits->totalMilliseconds);
        self::assertLessThanOrEqual(60000, $f->policy->limits->totalMilliseconds);
        self::assertSame(131072, $f->policy->limits->responseBytes);
    }

    public function testCredentialAndProviderFailuresAreRedactedBeforeAndAfterDispatch(): void
    {
        foreach ([new \RuntimeException(TransportFixture::canary()), new Response(401, ['Content-Type' => 'application/json'], TransportFixture::canary())] as $reply) {
            $f = new TransportFixture([$reply]);
            $result = $f->transport->send(TransportFixture::request(), Fixture::cancellation());
            $this->assertUnknown($result);
            ob_start();
            var_dump($f->transport, $f->http, $result, TransportFixture::request());
            $debug = (string) ob_get_clean();
            self::assertStringNotContainsString(TransportFixture::canary(), $debug);
            self::assertStringNotContainsString(TransportFixture::messages()[3]->text, $debug);
            self::assertSame(1, $f->http->sendCount());
        }
        $f = new TransportFixture();
        $f->credentials->fail = true;
        $this->assertNotSent($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
        self::assertSame(0, $f->http->sendCount());
        foreach ([$f->transport, $f->http] as $value) {
            $this->rejects(static fn () => serialize($value), \LogicException::class);
        }
    }

    public function testWrongModelRemainsAnObservationWithoutAcceptingADeploymentChange(): void
    {
        $request = TransportFixture::request();
        $original = $request->identity->digest();
        $f = new TransportFixture([TransportFixture::response(['model' => 'unexpected-revision'])]);
        $result = $f->transport->send($request, Fixture::cancellation());
        $this->assertUnknown($result);
        self::assertSame('unexpected-revision', $result->observedModelRef);
        self::assertSame(4, $result->usage->outputTokens);
        self::assertSame('chat-model', $request->identity->binding->model);
        self::assertEquals($original, $request->identity->digest());
        self::assertSame(1, $f->http->sendCount());
    }

    public function testInputOutputAndResponseLimitsRejectOverflowWithNoPartialText(): void
    {
        $request = TransportFixture::request();
        $f = new TransportFixture([], new A\AnswerLimits(inputBytes: strlen($request->bytes) - 1));
        $this->assertNotSent($f->transport->send($request, Fixture::cancellation()));
        self::assertSame(0, $f->http->sendCount());
        self::assertSame(0, $f->credentials->calls);
        $this->rejects(static fn () => H\OpenAiRequest::bytes(TransportFixture::binding(), [new H\ChatMessage(A\MessageRole::User, str_repeat('x', 32768))]));
        foreach ([
            [TransportFixture::response(), new A\AnswerLimits(outputBytes: 4), null],
            [new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 1025)), null, new H\HttpLimits(5, 100, 1024)],
            [TransportFixture::response(['choices' => [['message' => ['content' => str_repeat('x', 65537)]]]]), null, null],
            [TransportFixture::response(['usage' => ['completion_tokens' => 33]]), null, null],
        ] as [$reply, $limits, $http]) {
            $f = new TransportFixture([$reply], $limits, $http);
            $this->assertUnknown($f->transport->send($request, Fixture::cancellation()));
            self::assertSame(1, $f->http->sendCount());
        }
        $this->rejects(static fn () => new H\HttpLimits(0, 100, 1024));
        $this->rejects(static fn () => new H\HttpLimits(5, 120001, 1024));
    }

    public function testTimeoutAndCancellationDistinguishBeforeAndAfterDispatch(): void
    {
        $request = TransportFixture::request();
        $f = new TransportFixture();
        $f->clock->seconds = 120;
        $this->assertNotSent($f->transport->send($request, Fixture::cancellation()));
        self::assertSame(0, $f->http->sendCount());
        self::assertSame(0, $f->credentials->calls);
        $f = new TransportFixture();
        $this->assertNotSent($f->transport->send($request, Fixture::cancellation(true)));
        self::assertSame(0, $f->http->sendCount());
        $f->policy->duringPrepare = static function () use ($f): void {
            $f->clock->seconds = 120;
        };
        $this->assertNotSent($f->transport->send($request, Fixture::cancellation()));
        self::assertSame(0, $f->http->sendCount());
        $f = new TransportFixture([static function () use (&$f): Response {
            $f->clock->seconds = 120;

            return TransportFixture::response();
        }]);
        $this->assertUnknown($f->transport->send($request, Fixture::cancellation()));
        self::assertSame(1, $f->http->sendCount());
        $cancel = new class implements A\CancellationToken {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        $f = new TransportFixture([static function () use ($cancel): Response {
            $cancel->cancelled = true;

            return TransportFixture::response();
        }]);
        $this->assertUnknown($f->transport->send($request, $cancel));
        self::assertSame(1, $f->http->sendCount());
    }

    public function testMalformedBodiesAndNonFiniteUsageNeverBecomeSuccess(): void
    {
        foreach (TransportFixture::corpus()['malformed'] as $bytes) {
            $f = new TransportFixture([new Response(200, ['Content-Type' => 'application/json'], $bytes)]);
            $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
            self::assertSame(1, $f->http->sendCount());
        }
        foreach ([-1, '12', 1.5, 9007199254740992, [], true] as $invalid) {
            $f = new TransportFixture([TransportFixture::response(['usage' => ['prompt_tokens' => $invalid]])]);
            $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
        }
        $f = new TransportFixture([TransportFixture::response(['usage' => ['total_tokens' => -1]])]);
        $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
        $reply = TransportFixture::corpus()['response'];
        unset($reply['usage']);
        $f = new TransportFixture([new Response(200, ['Content-Type' => 'application/json'], json_encode($reply, JSON_THROW_ON_ERROR))]);
        $result = $f->transport->send(TransportFixture::request(), Fixture::cancellation());
        self::assertTrue($result->complete);
        self::assertNull($result->usage->inputTokens);
        self::assertNull($result->usage->outputTokens);
    }

    public function testPartialToolAndStreamingOutputNeverBecomesAnAnswer(): void
    {
        foreach (['length', 'content_filter', 'tool_calls', null] as $reason) {
            $f = new TransportFixture([TransportFixture::response(['choices' => [['finish_reason' => $reason]]])]);
            $result = $f->transport->send(TransportFixture::request(), Fixture::cancellation());
            $this->assertUnknown($result);
            self::assertSame(4, $result->usage->outputTokens);
        }
        foreach ([
            TransportFixture::response(['choices' => [['message' => ['tool_calls' => [['id' => 'call-1']]]]]]),
            TransportFixture::response(['choices' => [['message' => ['refusal' => 'refused']]]]),
            new Response(200, ['Content-Type' => 'text/event-stream'], 'data: ' . json_encode(TransportFixture::corpus()['response'])),
        ] as $reply) {
            $f = new TransportFixture([$reply]);
            $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
            self::assertSame(1, $f->http->sendCount());
        }
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->expects(self::once())->method('read')->willThrowException(new \RuntimeException('Interrupted response'));
        $stream->expects(self::once())->method('close');
        $f = new TransportFixture([(new Response(200, ['Content-Type' => 'application/json']))->withBody($stream)]);
        $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
        self::assertSame(1, $f->http->sendCount());
    }

    public function testEachFailureMakesOnlyOneSendWithoutRedirectRetryOrRepair(): void
    {
        foreach ([301, 307, 400, 401, 408, 429, 500, 503] as $status) {
            $reply = TransportFixture::response(status: $status)->withHeader('Location', 'https://other.invalid/chat')->withHeader('Retry-After', '0');
            $f = new TransportFixture([$reply, TransportFixture::response()]);
            $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
            self::assertSame(1, $f->http->sendCount());
        }
        foreach ([new \RuntimeException('Ambiguous connect/write/read timeout'), new A\TransportUnavailable(A\UnavailableComponent::Credentials)] as $failure) {
            $f = new TransportFixture([$failure, TransportFixture::response()]);
            $this->assertUnknown($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
            self::assertSame(1, $f->http->sendCount());
        }
    }

    public function testUnconfiguredOrUnsupportedHostPerformsNoNetworkAndReadinessIsLocal(): void
    {
        $f = new TransportFixture();
        $factory = new Psr17Factory();
        foreach ([
            new OpenAiCompatibleTransport($f->clock),
            new OpenAiCompatibleTransport($f->clock, deployment: $f->deployment, policy: $f->policy),
            new OpenAiCompatibleTransport($f->clock, $f->http, $factory, $factory, policy: $f->policy, deployment: $f->deployment),
        ] as $transport) {
            $outcome = $transport->readiness()->outcome(TransportFixture::request()->identity->invocationRef);
            self::assertSame(A\OutcomeCode::Unavailable, $outcome->code);
            $this->rejects(static fn () => $transport->send(TransportFixture::request(), Fixture::cancellation()), A\TransportUnavailable::class);
        }
        self::assertTrue($f->transport->readiness()->configured());
        self::assertTrue($f->transport->descriptor()->production);
        self::assertSame(0, $f->credentials->calls);
        self::assertSame(0, $f->policy->calls);
        $f->policy->allowed = false;
        $this->assertNotSent($f->transport->send(TransportFixture::request(), Fixture::cancellation()));
        $f->policy->allowed = true;
        $f->credentials->missing = true;
        $this->rejects(static fn () => $f->transport->send(TransportFixture::request(), Fixture::cancellation()), A\TransportUnavailable::class);
        self::assertSame(0, $f->http->sendCount());
        self::assertSame(0, $f->http->outboundBytes());
        $this->rejects(static fn () => new ScriptedHttpClient(new EffectiveProfile(ProfileMode::Production), []), D\DomainFailure::class);
        foreach (['http://example.invalid', 'https://user:pass@example.invalid', 'https://example.invalid/#fragment', 'file:///tmp/model'] as $endpoint) {
            $this->rejects(static fn () => new H\Deployment(TransportFixture::binding(), $endpoint));
        }
    }

    public function testChangedModelSettingsRolesOrBodyCannotReuseAdmittedIdentity(): void
    {
        $request = TransportFixture::request();
        $this->rejects(static fn () => new A\TransportRequest($request->identity, $request->bytes . ' '));
        foreach ([
            str_replace('chat-model', 'other-model', $request->bytes),
            str_replace('"stream":false', '"stream":true', $request->bytes),
            str_replace('"max_tokens":32', '"max_tokens":31', $request->bytes),
            str_replace('"model":"chat-model"', '"model":"wrong","model":"chat-model"', $request->bytes),
            str_replace('"temperature":0.2', '"temperature":1.2', $request->bytes),
            str_replace('"role":"system"', '"role":"user"', $request->bytes),
            $request->bytes . ' ',
        ] as $bytes) {
            $f = new TransportFixture();
            $this->assertNotSent($f->transport->send(TransportFixture::request($bytes), Fixture::cancellation()));
            self::assertSame(0, $f->http->sendCount());
            self::assertSame(0, $f->credentials->calls);
        }
        $f = new TransportFixture();
        $binding = TransportFixture::binding(['region' => 'other-region']);
        $this->assertNotSent($f->transport->send(TransportFixture::request(changes: ['binding' => $binding]), Fixture::cancellation()));
        self::assertSame(0, $f->http->sendCount());
    }

    private function assertUnknown(A\TransportResult $result): void
    {
        self::assertSame(A\DispatchCertainty::Unknown, $result->certainty);
        self::assertFalse($result->complete);
        self::assertNull($result->text);
    }

    private function assertNotSent(A\TransportResult $result): void
    {
        self::assertSame(A\DispatchCertainty::KnownNotSent, $result->certainty);
        self::assertFalse($result->complete);
        self::assertNull($result->text);
        self::assertNull($result->usage->inputTokens);
    }

    private function rejects(callable $action, string $class = \InvalidArgumentException::class): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);

            return;
        }
        self::fail('Invalid transport configuration/body accepted');
    }
}
