<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** The host allocates this stable ID. No URL, filename or content hash is an identity. */
final readonly class RegisterSource
{
    public function __construct(public SourceId $sourceId) {}
}
