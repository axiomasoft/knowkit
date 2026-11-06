<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class QualityDecision
{
    /** @param list<string> $diagnosticCodes */
    public function __construct(public Decision $decision, public Coverage $coverage, public array $diagnosticCodes)
    {
        ValueGuard::strings($diagnosticCodes, false, true);
    }
}
