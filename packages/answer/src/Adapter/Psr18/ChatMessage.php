<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

use KnowKit\Answer\Domain\Guard;
use KnowKit\Answer\Domain\MessageRole;

/** Explicit composed role; unlike caller history, host policy may use the system role. */
final readonly class ChatMessage
{
    public function __construct(
        public MessageRole $role,
        public string $text,
    ) {
        Guard::utf8($text, 32768);
    }

    public function __debugInfo(): array
    {
        return ['role' => $this->role->value, 'bytes' => strlen($this->text)];
    }
}
