<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;

/** Only committed application results reach the serializer. Failure payloads are always discarded. */
final readonly class Reply
{
    private ?D\FrozenJson $data;

    public function __construct(
        public D\Result $result,
        ?JsonObject $data = null,
    ) {
        $this->data = $result->outcome === D\Outcome::Success && $data !== null ? new D\FrozenJson($data) : null;
    }

    public static function success(JsonObject $data): self
    {
        return new self(result: D\Result::success(new D\FrozenJson($data)), data: $data);
    }

    public function data(): ?JsonObject
    {
        return $this->data?->toWire();
    }
}
