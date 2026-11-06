<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Vector
{
    /** @param list<float|int> $values */
    public function __construct(public array $values, public SpaceRef $space)
    {
        ValueGuard::vector($values, $space);
    }
}
