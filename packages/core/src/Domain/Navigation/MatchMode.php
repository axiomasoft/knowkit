<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

enum MatchMode: string
{
    case Exact = 'exact';
    case Whitespace = 'whitespace';
    case UnicodeNfd = 'unicode_nfd';
}
