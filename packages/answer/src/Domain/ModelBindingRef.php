<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Host handle, independent of corpus embedding configuration. */
final readonly class ModelBindingRef
{
    public function __construct(
        public string $value,
    ) {
        Guard::reference($value);
    }
}
