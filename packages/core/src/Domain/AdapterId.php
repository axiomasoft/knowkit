<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class AdapterId implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{0,127}$/D', $value) !== 1) { throw new \InvalidArgumentException('Exact adapter identifier required'); }
    }
    public function __toString(): string { return $this->value; }
}
