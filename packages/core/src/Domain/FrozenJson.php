<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};

/** Owns canonical bytes, never a caller's mutable nested objects. */
final readonly class FrozenJson
{
    private string $canonical;
    private JsonObject $wire;

    public function __construct(JsonObject $value)
    {
        $this->canonical = Jcs::canonicalize($value);
        $wire = StrictJson::decode($this->canonical, max(1048576, strlen($this->canonical)));
        if (!$wire instanceof JsonObject) {
            throw new \LogicException('Canonical JSON object required');
        }
        $this->wire = $wire;
    }

    public function toWire(): JsonObject
    {
        return clone $this->wire;
    }

    public function canonical(): string
    {
        return $this->canonical;
    }

    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    /** @return array{byteLength: int} */
    public function __debugInfo(): array
    {
        return ['byteLength' => strlen($this->canonical)];
    }
}
