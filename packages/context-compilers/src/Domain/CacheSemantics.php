<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Domain;

enum CacheSemantics: string
{
    case ExplicitServerObject = 'explicit_server_object';
    case ExplicitBreakpoints = 'explicit_breakpoints';
    case ImplicitPrefix = 'implicit_prefix';
    case LocalRadixPrefix = 'local_radix_prefix';
    case CompactNoCache = 'compact_no_cache';
}
