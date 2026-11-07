<?php

declare(strict_types=1);

namespace KnowKit\Application\SourceNavigation;

use KnowKit\Domain\Navigation\Projection;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** @internal Whitespace-only baseline; no trim, casefold, punctuation or canonical normalization. */
final class WhitespaceProjection
{
    private const PATTERN = '/\G([\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|.)/us';

    public function project(string $text): Projection
    {
        $projected = '';
        $map = '';
        $offset = 0;
        while (preg_match(self::PATTERN, $text, $match, 0, $offset) === 1) {
            $original = $match[0];
            $end = $offset + strlen($original);
            $value = $original;
            // Test against the exact Unicode WhiteSpace set, including multibyte singletons.
            if (preg_match('/^[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', $original) === 1) {
                $value = ' ';
            }
            $start = strlen($projected);
            $projected .= $value;
            $map .= pack('N4', $start, strlen($projected), $offset, $end);
            $offset = $end;
        }
        if ($offset !== strlen($text)) {
            throw new DomainFailure(Outcome::Incompatible, 'projection_failed');
        }

        return new Projection($projected, $map);
    }
}
