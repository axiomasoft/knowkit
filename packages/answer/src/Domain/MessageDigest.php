<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

final readonly class MessageDigest
{
    public function __construct(
        public MessageRole $role,
        public Sha256 $contentDigest,
    ) {
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['role' => $this->role->value, 'contentDigest' => $this->contentDigest->value]);
    }
}
