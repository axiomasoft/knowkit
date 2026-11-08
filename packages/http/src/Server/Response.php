<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Adapter\Cli\Response as Envelope;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {}

    public static function reply(Reply $reply, Route $route, D\Ulid $requestId): self
    {
        $result = $reply->result;
        $status = match ($result->outcome) {
            D\Outcome::Success => $route->successStatus,
            D\Outcome::Empty => 200,
            D\Outcome::Denied => self::deniedStatus(result: $result, route: $route),
            D\Outcome::Unknown, D\Outcome::Unavailable => 503,
            D\Outcome::Incompatible => 409,
        };

        if ($result->errorCode === 'resource_missing') {
            $status = 404;
        }
        if ($result->errorCode === 'quota_exceeded') {
            $status = 429;
        }

        return self::make(
            status: $status,
            envelope: Envelope::result(result: $result, data: $reply->data())->envelope,
            requestId: $requestId,
        );
    }

    private static function deniedStatus(D\Result $result, Route $route): int
    {
        if ($result->errorCode === 'unauthenticated') {
            return 401;
        }
        // Core retains Denied/POLICY_CHANGED for stale mutation epochs. The HTTP conflict
        // exception changes only the status; a disclosure policy change remains 403.
        if ($route->mutation && $result->errorCode === 'POLICY_CHANGED') {
            return 409;
        }

        return 403;
    }

    public static function error(int $status, string $code, D\Ulid $requestId): self
    {
        return self::make(
            status: $status,
            envelope: Envelope::error(code: $code, exit: 70)->envelope,
            requestId: $requestId,
        );
    }

    private static function make(int $status, JsonObject $envelope, D\Ulid $requestId): self
    {
        return new self(
            status: $status,
            body: (new Envelope(envelope: $envelope, exit: 0))->json(),
            headers: ['Content-Type' => 'application/json', 'Request-ID' => $requestId->value,
                'KnowKit-Schema-Version' => '1.0',
                ...($status === 429 ? ['Retry-After' => '60'] : []),
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'requestId' => $this->headers['Request-ID'] ?? null];
    }
}
