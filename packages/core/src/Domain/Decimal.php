<?php
declare(strict_types=1);

namespace KnowKit\Domain;

readonly class Decimal implements \Stringable
{
    protected const MAX = '9223372036854775807';

    final public function __construct(public string $value)
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1 || strlen($value) > strlen(static::MAX)
            || (strlen($value) === strlen(static::MAX) && strcmp($value, static::MAX) > 0)) {
            throw new \InvalidArgumentException('Bounded canonical decimal string required');
        }
    }

    public function __toString(): string { return $this->value; }
    public function next(): static
    {
        if ($this->value === static::MAX) { throw new DomainFailure(Outcome::Unavailable, 'decimal_exhausted'); }
        $digits = $this->value;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') { $digits[$i] = (string) ((int) $digits[$i] + 1); return new static($digits); }
            $digits[$i] = '0';
        }
        return new static('1' . $digits);
    }
}
