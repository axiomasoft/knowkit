<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class TransportDescriptor
{
    public function __construct(
        public string $id,
        public string $version,
        public bool $production,
        public bool $requiresCredentials = true,
    ) {
        Guard::reference($id);
        Guard::reference($version);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(get_object_vars($this));
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->version === $other->version
            && $this->production === $other->production
            && $this->requiresCredentials === $other->requiresCredentials;
    }
}
