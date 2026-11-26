<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Packed per-codepoint map: projected UTF-8 byte start/end, canonical UTF-8 byte start/end. */
final readonly class Projection
{
    /**
     * Canonical reordering stays inside one grapheme cluster. The normalizer rejects
     * clusters larger than 8192 input bytes; 16384 entries covers that expansion.
     */
    private const REORDER_WINDOW = 16384;

    private bool $ordered;

    public function __construct(
        public string $text,
        private string $map,
    ) {
        if (strlen($map) % 16 !== 0 || strlen($map) > 134217728) {
            throw new DomainFailure(Outcome::Incompatible, 'projection_limit');
        }
        $this->ordered = self::isOrdered($map);
    }

    /** @return array{int,int}|null A contiguous canonical interval; reject partial decompositions. */
    public function canonicalInterval(int $start, int $end): ?array
    {
        $first = $this->index($start, false);
        $last = $this->index($end, true);
        if ($first === null || $last === null || $first > $last) {
            return null;
        }
        $spans = [];
        for ($i = $first; $i <= $last; ++$i) {
            $entry = $this->entry($i);
            $spans[] = [$entry[2], $entry[3]];
        }
        sort($spans);
        [$low, $high] = $spans[0];
        foreach ($spans as [$a, $b]) {
            if ($a > $high) {
                return null;
            }
            $high = max($high, $b);
        }
        // Decomposition pieces of one source character share a canonical range. Reordering
        // can place another piece past the immediate neighbor; a partial selection is not a span.
        if ($this->overlapsOutside($first, $last, $low, $high)) {
            return null;
        }

        return [$low, $high];
    }

    /** Non-decreasing, non-nesting canonical ranges: only adjacent entries can overlap. */
    private static function isOrdered(string $map): bool
    {
        $count = intdiv(strlen($map), 16);
        $prevStart = -1;
        $prevEnd = 0;
        for ($index = 0; $index < $count; $index++) {
            $offset = $index * 16 + 8;
            $start = (ord($map[$offset]) << 24) | (ord($map[$offset + 1]) << 16) | (ord($map[$offset + 2]) << 8) | ord($map[$offset + 3]);
            $end = (ord($map[$offset + 4]) << 24) | (ord($map[$offset + 5]) << 16) | (ord($map[$offset + 6]) << 8) | ord($map[$offset + 7]);
            if ($start < $prevStart || ($start < $prevEnd && !($start === $prevStart && $end === $prevEnd))) {
                return false;
            }
            $prevStart = $start;
            $prevEnd = $end;
        }

        return true;
    }

    private function overlapsOutside(int $first, int $last, int $low, int $high): bool
    {
        $count = intdiv(strlen($this->map), 16);
        if ($this->ordered) {
            foreach ([$first - 1, $last + 1] as $index) {
                if ($index >= 0 && $index < $count && $this->overlaps($index, $low, $high)) {
                    return true;
                }
            }

            return false;
        }
        $begin = max(0, $first - self::REORDER_WINDOW);
        $end = min($count - 1, $last + self::REORDER_WINDOW);
        for ($index = $begin; $index <= $end; $index++) {
            if (($index < $first || $index > $last) && $this->overlaps($index, $low, $high)) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(int $index, int $low, int $high): bool
    {
        $entry = $this->entry($index);

        return $entry[2] < $high && $entry[3] > $low;
    }

    private function index(int $offset, bool $end): ?int
    {
        $low = 0;
        $high = intdiv(strlen($this->map), 16) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $value = $this->entry($mid)[$end ? 1 : 0];
            if ($value === $offset) {
                return $mid;
            }
            if ($value < $offset) {
                $low = $mid + 1;
                continue;
            }
            $high = $mid - 1;
        }

        return null;
    }

    /** @return array{int,int,int,int} */
    private function entry(int $index): array
    {
        /** @var array{1: int, 2: int, 3: int, 4: int}|false $unpacked */
        $unpacked = unpack('N4', $this->map, $index * 16);
        if ($unpacked === false) {
            throw new DomainFailure(Outcome::Incompatible, 'projection_limit');
        }

        return [$unpacked[1], $unpacked[2], $unpacked[3], $unpacked[4]];
    }
}
