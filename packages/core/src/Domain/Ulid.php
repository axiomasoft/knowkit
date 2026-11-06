<?php
declare(strict_types=1);

namespace KnowKit\Domain;

readonly class Ulid implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Canonical uppercase ULID required');
        }
    }

    public function __toString(): string { return $this->value; }
    public function equals(self $other): bool { return $other::class === static::class && $other->value === $this->value; }
}
