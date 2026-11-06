<?php
declare(strict_types=1);

namespace KnowKit\Domain;

readonly class Sha256 implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Lowercase SHA256 required');
        }
    }

    public function __toString(): string { return $this->value; }
    public function equals(self $other): bool { return $other::class === static::class && hash_equals($this->value, $other->value); }
}
