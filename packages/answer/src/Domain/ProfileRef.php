<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

/** An exact immutable retrieval profile, never a mutable current-profile alias. */
final readonly class ProfileRef
{
    public function __construct(
        public string $id,
        public string $revision,
        public Sha256 $digest,
    ) {
        Guard::reference($id);
        Guard::reference($revision);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['id' => $this->id, 'revision' => $this->revision, 'digest' => $this->digest->value]);
    }
}
