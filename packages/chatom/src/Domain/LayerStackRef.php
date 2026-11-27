<?php

declare(strict_types=1);

namespace KnowKit\Chatom\Domain;

use Stringable;

/** Opaque reference to a resolved layer stack pinned on a conversation binding. */
final readonly class LayerStackRef implements Stringable
{
    public function __construct(
        public string $value,
    ) {
        ConversationRef::assertReference($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
