<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

final readonly class InvocationKey
{
    public function __construct(
        public string $value,
    ) {
        Guard::utf8($value, 128, false);
    }
}
