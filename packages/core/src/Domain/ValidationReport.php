<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class ValidationReport
{
    /** @param list<string> $errorCodes */
    public function __construct(public string $status, public array $errorCodes)
    {
        if (!in_array($status, ['valid','invalid','unknown'], true)) {
            throw new \InvalidArgumentException('Validation status required');
        }
        ValueGuard::strings($errorCodes, false, true);
        if ($status === 'valid' && $errorCodes !== []) { throw new \InvalidArgumentException('Invalid successful validation'); }
    }
}
