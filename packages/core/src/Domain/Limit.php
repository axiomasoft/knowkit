<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Limit
{
    public function __construct(public int $k)
    {
        if ($k < 1 || $k > 4096) { throw new \InvalidArgumentException('Bounded top-k required'); }
    }
}
