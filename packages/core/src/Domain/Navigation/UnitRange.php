<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

/** Inclusive physical page numbers or 1-based ordinals; local IDs for blocks/sections. */
final readonly class UnitRange
{
    private function __construct(
        public string $kind,
        public int $first,
        public int $last,
        public ?D\LocalId $root = null,
    ) {
        if ($first < 1 || $last < $first || $last > 4294967295 || $last - $first >= 24) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_locator');
        }
    }

    public static function pages(int $first, ?int $last = null): self
    {
        return new self('page', $first, $last ?? $first);
    }

    public static function segments(int $first, ?int $last = null): self
    {
        return new self('segment', $first, $last ?? $first);
    }

    public static function blocks(int $first, ?int $last = null): self
    {
        return new self('block', $first, $last ?? $first);
    }

    public static function section(D\LocalId $heading): self
    {
        return new self('section', 1, 1, $heading);
    }

    public function requireLimits(Limits $limits): void
    {
        if ($this->last - $this->first + 1 > min($limits->maxRangeWidth, $limits->maxUnits)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
    }
}
