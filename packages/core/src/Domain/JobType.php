<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class JobType implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) { throw new \InvalidArgumentException('Job type required'); }
    }
    public function __toString(): string { return $this->value; }
}
