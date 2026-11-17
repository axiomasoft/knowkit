<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Server\Contract;
use KnowKit\Http\Server\Request;
use KnowKit\Http\Server\Route;

/** Closed operation/schema selection, not an arbitrary URL or identity channel. */
final readonly class Call
{
    private D\FrozenJson $body;

    /** @param array<string, D\Ulid> $parameters */
    public function __construct(
        public Route $route,
        public array $parameters,
        JsonObject $body,
        public D\Ulid $requestId,
        public ?D\MutationContext $mutation = null,
    ) {
        $this->assertParameters($parameters);
        $this->body = new D\FrozenJson($body);
    }

    public static function operation(
        string $operation,
        JsonObject $body,
        D\Ulid $requestId,
        array $parameters = [],
        ?D\MutationContext $mutation = null,
    ): self {
        foreach ((new Contract())->routes() as $route) {
            if ($route->operation === $operation) {
                return new self(
                    route: $route, parameters: $parameters, body: $body,
                    requestId: $requestId, mutation: $mutation,
                );
            }
        }
        throw new \InvalidArgumentException('Unknown HTTP operation');
    }

    public function request(Contract $contract, string $version): Request
    {
        $body = $this->body->toWire();
        $contract->validate(schema: $this->route->schema, body: $body);
        $target = $this->target();
        [$canonical] = $contract->match(new Request(method: $this->route->method, target: $target));
        if (!$canonical->equals($this->route) || $body->has('usageContext')) {
            throw new \InvalidArgumentException('Invalid HTTP call');
        }
        $headers = $this->headers($version);

        return new Request(
            method: $this->route->method, target: $target, headers: $headers,
            body: $this->route->method === 'GET' ? '' : Jcs::canonicalize($body),
        );
    }

    private function target(): string
    {
        preg_match_all('/\{([a-z]+)\}/', $this->route->path, $matches);
        if (array_diff(array_keys($this->parameters), $matches[1]) !== []
            || array_diff($matches[1], array_keys($this->parameters)) !== []) {
            throw new \InvalidArgumentException('Exact route parameters required');
        }
        $target = $this->route->path;
        foreach ($this->parameters as $name => $id) {
            $target = str_replace('{' . $name . '}', $id->value, $target);
        }

        return $target;
    }

    private function assertParameters(array $parameters): void
    {
        foreach ($parameters as $id) {
            if (!$id instanceof D\Ulid) {
                throw new \InvalidArgumentException('Typed route ID required');
            }
        }
    }

    private function headers(string $version): array
    {
        if ($this->route->mutation !== ($this->mutation !== null)) {
            throw new \InvalidArgumentException('Mutation context must match operation');
        }
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json',
            'KnowKit-Schema-Version' => $version, 'Request-ID' => $this->requestId->value];
        if ($this->mutation === null) {
            return $headers;
        }
        $m = $this->mutation;
        if ($m->toWire()->get('requestId') !== $this->requestId->value
            || ($this->route->epoch && $m->expectedEpoch() === null)
            || ($this->route->revision && $m->expectedRevision() === null)
            || (!$this->route->epoch && $m->expectedEpoch() !== null)
            || (!$this->route->revision && !$this->route->revisionAllowed && $m->expectedRevision() !== null)) {
            throw new \InvalidArgumentException('Mutation headers must match operation');
        }
        $headers['Idempotency-Key'] = $m->idempotencyKey()->value;
        if ($m->expectedEpoch() !== null) {
            $headers['Expected-Epoch'] = $m->expectedEpoch()->value;
        }
        if ($m->expectedRevision() !== null) {
            $headers['Expected-Revision'] = $m->expectedRevision()->value;
        }

        return $headers;
    }

    public function retrySafe(): bool
    {
        return $this->route->mutation || in_array($this->route->operation, [
            'search', 'capabilities', 'readiness', 'coverage', 'ingestJobStatus', 'purgeStatus', 'usageInvalidations',
        ], true);
    }

    public function __debugInfo(): array
    {
        return ['operation' => $this->route->operation, 'requestId' => $this->requestId->value];
    }
}
