<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** The single UTC type for Clock::now and BlobStore::listUnreferenced (D4). */
final readonly class UtcInstant implements \Stringable
{
    private \DateTimeImmutable $instant;

    public function __construct(public string $value)
    {
        if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z$/D', $value, $m) !== 1) {
            throw new \InvalidArgumentException('UTC instant required');
        }
        $formatted = $m[1] . 'T' . $m[2] . '.' . str_pad($m[3] ?? '', 6, '0') . 'Z';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $formatted, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $formatted) {
            throw new \InvalidArgumentException('Invalid UTC calendar instant');
        }
        $this->instant = $date;
    }

    public function compare(self $other): int
    {
        return $this->instant <=> $other->instant;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function plusSeconds(int $seconds): self
    {
        return new self($this->instant->modify(sprintf('%+d seconds', $seconds))->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
