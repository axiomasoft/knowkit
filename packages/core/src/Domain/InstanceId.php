<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class InstanceId implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $value) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_identifier');
        }

        return new self($value);
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
