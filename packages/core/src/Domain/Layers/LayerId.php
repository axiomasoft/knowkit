<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

final readonly class LayerId implements \Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Canonical LayerId required');
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function utf16BeKey(): string
    {
        return (string) mb_convert_encoding($this->value, 'UTF-16BE', 'UTF-8');
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
