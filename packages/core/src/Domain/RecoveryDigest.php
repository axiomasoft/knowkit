<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{Jcs, JsonObject};

/** Ordered, domain-separated inventory/journal chain; never a hash of database JSON formatting. */
final class RecoveryDigest
{
    public static function seed(): string { return hash('sha256', 'knowkit:recovery:v1'); }
    public static function add(string $previous, JsonObject $entry): string
    { new Sha256($previous); return hash('sha256', $previous."\n".Jcs::canonicalize($entry)); }
}
