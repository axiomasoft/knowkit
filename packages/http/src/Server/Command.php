<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Validated transport input. Body access returns a copy; path IDs never select a tenant. */
final readonly class Command
{
    private D\FrozenJson $body;

    /** @param array<string, D\Ulid> $parameters */
    public function __construct(
        public Route $route,
        public array $parameters,
        JsonObject $body,
        public D\Ulid $requestId,
        public ?D\MutationContext $mutation,
        public ?D\Query $query = null,
        public ?D\Limit $limit = null,
        public ?D\DisclosureRequest $disclosure = null,
        public ?D\Reading\ReadRequest $read = null,
        public ?D\SourceBytes $upload = null,
    ) {
        $this->body = new D\FrozenJson($body);
    }

    public function body(): JsonObject
    {
        return $this->body->toWire();
    }

    public function __debugInfo(): array
    {
        return ['operation' => $this->route->operation, 'requestId' => $this->requestId->value];
    }
}
