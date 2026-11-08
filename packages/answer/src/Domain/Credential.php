<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Ephemeral host secret; never part of a profile, binding, audit or serialization. */
final class Credential
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $value,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('Nonempty credential required');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function __debugInfo(): array
    {
        return ['present' => true];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialized');
    }
}
