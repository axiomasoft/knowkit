<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject, StrictJson};

/** Owns canonical bytes, never a caller's mutable nested objects. */
final readonly class FrozenJson
{
    private string $canonical;
    public function __construct(JsonObject $value) { $this->canonical = Jcs::canonicalize($value); }
    public function toWire(): JsonObject { return StrictJson::decode($this->canonical, max(1048576, strlen($this->canonical))); }
    public function canonical(): string { return $this->canonical; }
    public function __debugInfo(): array { return ['byteLength' => strlen($this->canonical)]; }
}
