<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class LocalId implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value) !== 1) { throw new \InvalidArgumentException('Local identifier required'); }
    }
    public function __toString(): string { return $this->value; }
}
