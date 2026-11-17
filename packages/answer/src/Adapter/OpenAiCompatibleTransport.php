<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use DateTimeImmutable;
use KnowKit\Answer\Adapter\Psr18\Deployment;
use KnowKit\Answer\Adapter\Psr18\HostHttpPolicy;
use KnowKit\Answer\Adapter\Psr18\HttpLimits;
use KnowKit\Answer\Adapter\Psr18\OpenAiRequest;
use KnowKit\Answer\Adapter\Psr18\OpenAiResponse;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\CredentialResolver;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Answer\Profile\Readiness;
use KnowKit\Port\Clock;
use KnowKit\Port\Tokenizer;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** One host-configured, non-streaming chat-completions POST. Never retries or discovers endpoints. */
final readonly class OpenAiCompatibleTransport implements ModelTransport
{
    public function __construct(
        private Clock $clock,
        private ?ClientInterface $client = null,
        private ?RequestFactoryInterface $requests = null,
        private ?StreamFactoryInterface $streams = null,
        private ?CredentialResolver $credentials = null,
        private ?HostHttpPolicy $policy = null,
        private ?Deployment $deployment = null,
        private ?Tokenizer $tokenizer = null,
    ) {
    }

    public function descriptor(): A\TransportDescriptor
    {
        return new A\TransportDescriptor('openai-compatible-psr18', '1', true);
    }

    /** Metadata only. No credential resolution, policy invocation, DNS or provider IO. */
    public function readiness(): Readiness
    {
        if ($this->deployment === null || $this->policy === null) {
            return new Readiness(A\UnavailableComponent::Transport);
        }
        if ($this->client === null || $this->requests === null || $this->streams === null) {
            return new Readiness(A\UnavailableComponent::HttpClient);
        }
        if ($this->credentials === null || !$this->deployment->binding->credentialRef->present) {
            return new Readiness(A\UnavailableComponent::Credentials);
        }

        return new Readiness(null);
    }

    public function send(A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult
    {
        $unavailable = $this->readiness()->unavailable;
        if ($unavailable !== null) {
            throw new A\TransportUnavailable($unavailable);
        }
        $started = hrtime(true);
        try {
            $this->requireRequest($request);
            if ($this->stopped($request, $cancellation)) {
                return $this->notSent();
            }
            $http = $this->prepare($request, $started);
            if ($http === null || $this->stopped($request, $cancellation) || $this->elapsed($started)) {
                return $this->notSent();
            }
            // Host credential/policy callbacks may outlive the pinned capability lease.
            $request->identity->binding->requireCurrent($this->now());
        } catch (A\TransportUnavailable $unavailable) {
            throw $unavailable;
        } catch (\Throwable) {
            return $this->notSent();
        }

        return $this->exchange($http, $request, $cancellation, $started);
    }

    private function exchange(
        \Psr\Http\Message\RequestInterface $http,
        A\TransportRequest $request,
        A\CancellationToken $cancellation,
        int $started,
    ): A\TransportResult {
        // From the first sendRequest instruction onward, failure cannot prove non-dispatch.
        try {
            if ($this->client === null || $this->deployment === null) {
                return OpenAiResponse::unknown();
            }
            $response = $this->client->sendRequest($http);
            $bytes = $this->read($response->getBody(), $request, $cancellation, $started);
            if ($bytes === null || $this->stopped($request, $cancellation) || $this->elapsed($started)) {
                return OpenAiResponse::unknown();
            }

            return OpenAiResponse::parse($response, $bytes, $this->deployment);
        } catch (\Throwable) {
            return OpenAiResponse::unknown();
        }
    }

    private function read(
        \Psr\Http\Message\StreamInterface $body,
        A\TransportRequest $request,
        A\CancellationToken $cancellation,
        int $started,
    ): ?string {
        if ($this->deployment === null) {
            return null;
        }
        $limit = $this->deployment->http->responseBytes;
        try {
            $bytes = '';
            while (!self::isEof($body)) {
                if ($this->stopped($request, $cancellation) || $this->elapsed($started)) {
                    return null;
                }
                $chunk = $body->read(max(1, min(8192, $limit + 1 - strlen($bytes))));
                if ($chunk === '') {
                    if (self::isEof($body)) {
                        break;
                    }

                    return null;
                }
                if (strlen($bytes) + strlen($chunk) > $limit) {
                    return null;
                }
                $bytes .= $chunk;
            }

            return $bytes;
        } finally {
            $body->close();
        }
    }

    /** @phpstan-impure */
    private static function isEof(\Psr\Http\Message\StreamInterface $body): bool
    {
        return $body->eof();
    }

    private function requireRequest(A\TransportRequest $request): void
    {
        if ($this->deployment === null) {
            throw new A\TransportUnavailable(A\UnavailableComponent::Transport);
        }
        $binding = $request->identity->binding;
        if (!$binding->equals($this->deployment->binding) || !$binding->transport->equals($this->descriptor())) {
            throw new \InvalidArgumentException('Pinned deployment mismatch');
        }
        $binding->requireCurrent($this->now());
        OpenAiRequest::requireExact($request);
        $binding->assertInput($request->bytes, '', $this->deployment->limits, $this->tokenizer);
    }

    private function prepare(A\TransportRequest $request, int $started): ?\Psr\Http\Message\RequestInterface
    {
        if ($this->deployment === null || $this->policy === null || $this->client === null
            || $this->requests === null || $this->streams === null || $this->credentials === null) {
            return null;
        }
        $binding = $request->identity->binding;
        $credential = $this->credentials->resolve($binding->scope, $binding->credentialRef, $binding);
        if ($credential === null) {
            throw new A\TransportUnavailable(A\UnavailableComponent::Credentials);
        }
        if (preg_match('/^[\x21-\x7e]+$/D', $credential->reveal()) !== 1) {
            throw new A\TransportUnavailable(A\UnavailableComponent::Credentials);
        }
        $remaining = (int) floor(((float) $request->identity->deadline->format('U.u') - (float) $this->now()->format('U.u')) * 1000);
        $total = min($remaining, $this->deployment->http->totalMilliseconds - (int) ceil((hrtime(true) - $started) / 1000000), $this->deployment->limits->deadlineSeconds * 1000);
        if ($total < 1 || !$this->policy->prepare($this->client, $this->deployment, new HttpLimits(
            min($total, $this->deployment->http->connectMilliseconds),
            $total,
            $this->deployment->http->responseBytes,
        ))) {
            return null;
        }

        return $this->requests->createRequest('POST', $this->deployment->endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $credential->reveal())
            ->withBody($this->streams->createStream($request->bytes));
    }

    /** Live host observations must not be treated as memoized values.
     * @phpstan-impure
     */
    private function stopped(A\TransportRequest $request, A\CancellationToken $cancellation): bool
    {
        $now = $this->now();

        return $cancellation->isCancelled() || $request->identity->deadline <= $now
            || $request->identity->egressDecision->checkedAt > $now || $request->identity->egressDecision->expiresAt <= $now;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->clock->now()->value);
    }

    private function notSent(): A\TransportResult
    {
        return new A\TransportResult(A\DispatchCertainty::KnownNotSent, null, false, new A\UsageObservation());
    }

    private function elapsed(int $started): bool
    {
        if ($this->deployment === null) {
            return true;
        }
        $limit = min($this->deployment->http->totalMilliseconds, $this->deployment->limits->deadlineSeconds * 1000);

        return (hrtime(true) - $started) / 1000000 >= $limit;
    }

    public function __debugInfo(): array
    {
        return ['descriptor' => $this->descriptor(), 'configured' => $this->readiness()->configured()];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Transport collaborators cannot be serialized');
    }
}
