<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Packed per-codepoint map: projected UTF-8 byte start/end, canonical UTF-8 byte start/end. */
final readonly class Projection
{
    public function __construct(
        public string $text,
        private string $map,
    ) {
        if (strlen($map) % 16 !== 0 || strlen($map) > 134217728) {
            throw new DomainFailure(Outcome::Incompatible, 'projection_limit');
        }
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
        // Canonical reordering/decomposition may map several outputs to the same input.
        foreach ([$first - 1, $last + 1] as $i) {
            if ($i >= 0 && $i < intdiv(strlen($this->map), 16)) {
                $entry = $this->entry($i);
                if ($entry[2] < $high && $entry[3] > $low) {
                    return null;
                }
            }
        }

        return [$low, $high];
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
