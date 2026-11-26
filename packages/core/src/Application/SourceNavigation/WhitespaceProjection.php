<?php

declare(strict_types=1);

namespace KnowKit\Application\SourceNavigation;

use KnowKit\Domain\Navigation\Projection;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** @internal Whitespace-only baseline; no trim, casefold, punctuation or canonical normalization. */
final class WhitespaceProjection
{
    /** Whitespace runs collapse to one space; every other code point keeps its own map entry. */
    private const RUN = '/\G(([\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+)|([^\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+))/u';

    public function project(string $text): Projection
    {
        $projected = '';
        $map = '';
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length && preg_match(self::RUN, $text, $match, 0, $offset) === 1) {
            $original = $match[0];
            $end = $offset + strlen($original);
            if ($match[2] !== '') {
                $start = strlen($projected);
                $projected .= ' ';
                $map .= pack('N4', $start, strlen($projected), $offset, $end);
            } else {
                $projectedStart = strlen($projected);
                $projected .= $original;
                $index = 0;
                $run = strlen($original);
                while ($index < $run) {
                    $width = self::sequence($original, $index);
                    if ($width < 1 || $index + $width > $run) {
                        throw new DomainFailure(Outcome::Incompatible, 'projection_failed');
                    }
                    $map .= pack('N4', $projectedStart, $projectedStart + $width, $offset + $index, $offset + $index + $width);
                    $projectedStart += $width;
                    $index += $width;
                }
            }
            $offset = $end;
        }
        if ($offset !== $length) {
            throw new DomainFailure(Outcome::Incompatible, 'projection_failed');
        }

        return new Projection($projected, $map);
    }

    /** Byte width of one UTF-8 code point already accepted by the /u run matcher. */
    private static function sequence(string $text, int $offset): int
    {
        $byte = ord($text[$offset]);

        return $byte < 0x80 ? 1 : ($byte < 0xE0 ? 2 : ($byte < 0xF0 ? 3 : 4));
    }
}
