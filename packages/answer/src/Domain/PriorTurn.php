<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class PriorTurn
{
    public function __construct(
        public MessageRole $role,
        public string $text,
    ) {
        Guard::utf8($text, 16384);
        if ($role === MessageRole::System) {
            throw new \InvalidArgumentException('Supplied history cannot set host policy');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['role' => $this->role->value, 'text' => $this->text]);
    }

    public function __debugInfo(): array
    {
        return ['role' => $this->role->value, 'bytes' => strlen($this->text)];
    }
}
