<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Immutable decoded JSON object. Keeps {} distinct from [] and preserves member order;
 * member names are always exposed as strings (PHP int-like array keys are cast back).
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final class JsonObject implements \IteratorAggregate, \Countable
{
    /** @param array<array-key, mixed> $members */
    public function __construct(private readonly array $members = [])
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->members);
    }

    public function get(string $name): mixed
    {
        return $this->members[$name] ?? null;
    }

    public function with(string $name, mixed $value): self
    {
        $members = $this->members;
        $members[$name] = $value;
        return new self($members);
    }

    public function without(string $name): self
    {
        $members = $this->members;
        unset($members[$name]);
        return new self($members);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn(int|string $key): string => (string) $key, array_keys($this->members));
    }

    public function getIterator(): \Generator
    {
        foreach ($this->members as $name => $value) {
            yield (string) $name => $value;
        }
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function equals(self $other): bool
    {
        return Jcs::canonicalize($this) === Jcs::canonicalize($other);
    }
}
