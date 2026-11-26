<?php

declare(strict_types=1);

namespace KnowKit\Application\SourceNavigation;

use KnowKit\Domain\Navigation\CanonicalUnit;
use KnowKit\Domain\Navigation\SourceUnitRef;

/** @internal Context/verified positions are not a second public navigation API. */
final readonly class TextQuoteSelector
{
    public function __construct(
        public string $exact,
        private string $prefix = '',
        private string $suffix = '',
        private ?SourceUnitRef $positionUnit = null,
        private ?int $position = null,
    ) {}

    public function accepts(CanonicalUnit $unit, int $start, int $end): bool
    {
        if ($this->position !== null && ($this->positionUnit === null || !$unit->ref->equals($this->positionUnit) || $start !== $this->position)) {
            return false;
        }
        $text = $unit->text();

        return $start >= strlen($this->prefix)
            && substr($text, $start - strlen($this->prefix), strlen($this->prefix)) === $this->prefix
            && substr($text, $end, strlen($this->suffix)) === $this->suffix;
    }
}
