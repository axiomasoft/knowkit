<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class IdempotencyKey implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[\x21-\x7e]{1,128}$/D', $value) !== 1) { throw new \InvalidArgumentException('Idempotency key required'); }
    }
    public function __toString(): string { return $this->value; }
}
