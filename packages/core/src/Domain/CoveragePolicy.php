<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class CoveragePolicy
{
    /** @param list<string> $requiredFeatures */
    public function __construct(public bool $allowPartial = false, public array $requiredFeatures = [])
    {
        ValueGuard::strings($requiredFeatures, true, true);
    }
}
