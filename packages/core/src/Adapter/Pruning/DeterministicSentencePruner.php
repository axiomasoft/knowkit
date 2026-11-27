<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Pruning;

use KnowKit\Domain\Pruning\AtomicByteRange;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PrunedSelection;
use KnowKit\Domain\Pruning\PrunedSubspan;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\QueryBinding;
use KnowKit\Port\ContextPruner;

/** Zero-dependency deterministic sentence pruner preserving UTF-8 byte offsets and indivisible atoms. */
final readonly class DeterministicSentencePruner implements ContextPruner
{
    /** @var list<string> */
    private const ANAPHORIC_MARKERS = [
        'при этом',
        'данный',
        'данная',
        'данное',
        'данные',
        'эта',
        'этот',
        'это',
        'эти',
        'он',
        'она',
        'оно',
        'они',
        'therefore',
        'this',
        'that',
        'these',
        'those',
        'it',
        'its',
        'such',
    ];

    /**
     * @param list<PrunableUnit> $units
     * @return list<PrunedSelection>
     */
    public function prune(
        QueryBinding $query,
        array $units,
        PruningPolicy $policy,
    ): array {
        $queryTokens = $this->tokenize($query->normalized->text() . ' ' . $query->original->text());
        $results = [];

        foreach ($units as $unit) {
            $results[] = $this->pruneUnit($unit, $queryTokens, $policy);
        }

        return $results;
    }

    /**
     * @param list<string> $queryTokens
     */
    private function pruneUnit(PrunableUnit $unit, array $queryTokens, PruningPolicy $policy): PrunedSelection
    {
        $text = $unit->text();
        $segments = $this->segment($text, $unit->atomicRanges, $unit->contextEndByte);
        if ($segments === []) {
            return new PrunedSelection($unit->chunkRef(), [new PrunedSubspan(0, strlen($text), 1000)]);
        }

        $scores = [];
        foreach ($segments as $index => $segment) {
            $scores[$index] = $this->scoreSegment($segment['text'], $queryTokens);
        }

        $selected = [];
        foreach ($segments as $index => $segment) {
            if ($scores[$index] >= $policy->thresholdPermille) {
                $selected[$index] = true;
            }
        }

        if ($policy->alwaysKeepContext) {
            $selected[0] = true;
        }

        if ($policy->keepAdjacentForCoreference) {
            $initialSelected = array_keys($selected);
            foreach ($initialSelected as $index) {
                if ($index > 0 && $this->startsWithAnaphora($segments[$index]['text'])) {
                    $selected[$index - 1] = true;
                }
            }
        }

        if ($selected === []) {
            $bestIndex = 0;
            $bestScore = -1;
            foreach ($scores as $index => $score) {
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }
            $selected[$bestIndex] = true;
        }

        // Enforce minKeptBytes by adding highest-scoring unselected segments.
        $keptBytes = $this->totalBytes($segments, $selected);
        if ($keptBytes < $policy->minKeptBytes) {
            $remaining = [];
            foreach ($segments as $index => $segment) {
                if (!isset($selected[$index])) {
                    $remaining[$index] = $scores[$index];
                }
            }
            arsort($remaining, SORT_NUMERIC);
            foreach (array_keys($remaining) as $index) {
                $selected[$index] = true;
                $keptBytes += $segments[$index]['end'] - $segments[$index]['start'];
                if ($keptBytes >= $policy->minKeptBytes) {
                    break;
                }
            }
        }

        // Enforce maxKeptBytes by dropping lowest-scoring non-context segments if needed.
        if ($this->totalBytes($segments, $selected) > $policy->maxKeptBytes && count($selected) > 1) {
            $droppable = [];
            foreach (array_keys($selected) as $index) {
                if ($policy->alwaysKeepContext && $index === 0) {
                    continue;
                }
                $droppable[$index] = $scores[$index];
            }
            asort($droppable, SORT_NUMERIC);
            foreach (array_keys($droppable) as $index) {
                if (count($selected) <= 1) {
                    break;
                }
                unset($selected[$index]);
                if ($this->totalBytes($segments, $selected) <= $policy->maxKeptBytes) {
                    break;
                }
            }
        }

        ksort($selected, SORT_NUMERIC);
        $subspans = [];
        foreach (array_keys($selected) as $index) {
            $subspans[] = new PrunedSubspan(
                $segments[$index]['start'],
                $segments[$index]['end'],
                $scores[$index],
            );
        }

        return new PrunedSelection($unit->chunkRef(), $subspans);
    }

    /**
     * @param list<AtomicByteRange> $atomicRanges
     * @return list<array{start: int, end: int, text: string}>
     */
    private function segment(string $text, array $atomicRanges, ?int $contextEndByte): array
    {
        $len = strlen($text);
        if ($len === 0) {
            return [];
        }

        $sortedAtoms = $atomicRanges;
        usort($sortedAtoms, static fn (AtomicByteRange $a, AtomicByteRange $b): int => $a->startByte <=> $b->startByte);

        $segments = [];
        $cursor = 0;

        if ($contextEndByte !== null && $contextEndByte > 0 && $contextEndByte <= $len) {
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($sortedAtoms as $atom) {
                    if ($atom->startByte < $contextEndByte && $atom->endByte > $contextEndByte) {
                        $contextEndByte = min($len, $atom->endByte);
                        $changed = true;
                    }
                }
            }
            $trimmed = $this->trimSpan($text, 0, $contextEndByte);
            if ($trimmed !== null) {
                $segments[] = $trimmed;
            }
            $cursor = $contextEndByte;
        }

        foreach ($sortedAtoms as $atom) {
            if ($atom->endByte <= $cursor) {
                continue;
            }
            if ($atom->startByte > $cursor) {
                foreach ($this->splitPlainRange($text, $cursor, $atom->startByte) as $seg) {
                    $segments[] = $seg;
                }
            }
            $atomStart = max($cursor, $atom->startByte);
            $atomEnd = $atom->endByte;
            if ($atomStart < $atomEnd) {
                $segments[] = [
                    'start' => $atomStart,
                    'end' => $atomEnd,
                    'text' => substr($text, $atomStart, $atomEnd - $atomStart),
                ];
            }
            $cursor = $atomEnd;
        }

        if ($cursor < $len) {
            foreach ($this->splitPlainRange($text, $cursor, $len) as $seg) {
                $segments[] = $seg;
            }
        }

        return $segments;
    }

    /**
     * @return list<array{start: int, end: int, text: string}>
     */
    private function splitPlainRange(string $text, int $rangeStart, int $rangeEnd): array
    {
        $slice = substr($text, $rangeStart, $rangeEnd - $rangeStart);
        if ($slice === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+|\R+/u', $slice, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if (!is_array($parts)) {
            $trimmed = $this->trimSpan($text, $rangeStart, $rangeEnd);
            return $trimmed === null ? [] : [$trimmed];
        }

        $segments = [];
        foreach ($parts as [$part, $relOffset]) {
            $partStart = $rangeStart + $relOffset;
            $partEnd = $partStart + strlen($part);
            $trimmed = $this->trimSpan($text, $partStart, $partEnd);
            if ($trimmed !== null) {
                $segments[] = $trimmed;
            }
        }

        return $segments;
    }

    /**
     * @return array{start: int, end: int, text: string}|null
     */
    private function trimSpan(string $text, int $start, int $end): ?array
    {
        $slice = substr($text, $start, $end - $start);
        if ($slice === '') {
            return null;
        }
        if (preg_match('/^\s+/u', $slice, $lead) === 1) {
            $start += strlen($lead[0]);
        }
        $slice = substr($text, $start, $end - $start);
        if ($slice === '' || preg_match('/^\s*$/u', $slice) === 1) {
            return null;
        }
        if (preg_match('/\s+$/u', $slice, $trail) === 1) {
            $end -= strlen($trail[0]);
        }
        if ($start >= $end) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'text' => substr($text, $start, $end - $start),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        preg_match_all('~[\p{L}\p{N}]+(?:[-_./:][\p{L}\p{N}]+)*~u', $lower, $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            if (mb_strlen($token, 'UTF-8') >= 2 || preg_match('/\p{N}/u', $token) === 1) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param list<string> $queryTokens
     */
    private function scoreSegment(string $segmentText, array $queryTokens): int
    {
        if ($queryTokens === []) {
            return 1000;
        }
        $segmentTokens = array_flip($this->tokenize($segmentText));
        $lower = mb_strtolower($segmentText, 'UTF-8');
        $matched = 0;
        foreach ($queryTokens as $token) {
            if (isset($segmentTokens[$token]) || str_contains($lower, $token)) {
                $matched++;
            }
        }

        $score = intdiv($matched * 1000, count($queryTokens));

        return max(0, min(1000, $score));
    }

    private function startsWithAnaphora(string $segmentText): bool
    {
        $lower = mb_strtolower(ltrim($segmentText), 'UTF-8');
        foreach (self::ANAPHORIC_MARKERS as $marker) {
            if (str_starts_with($lower, $marker)) {
                $nextOffset = strlen($marker);
                if ($nextOffset === strlen($lower) || preg_match('/^[\s,.:;!?-]/u', substr($lower, $nextOffset)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{start: int, end: int, text: string}> $segments
     * @param array<int, bool> $selected
     */
    private function totalBytes(array $segments, array $selected): int
    {
        $bytes = 0;
        foreach (array_keys($selected) as $index) {
            $bytes += $segments[$index]['end'] - $segments[$index]['start'];
        }

        return $bytes;
    }
}
