<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Exact named profile; never resolves a misspelling to a default. */
final readonly class ProfileId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}[a-z0-9]$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Exact profile ID required');
        }
    }
}
