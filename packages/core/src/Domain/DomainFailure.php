<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Safe machine-only failure. Never attach document content, inputs or credentials. */
final class DomainFailure extends \DomainException
{
    public function __construct(public readonly Outcome $outcome, public readonly string $errorCode)
    {
        if (in_array($outcome, [Outcome::Success, Outcome::Empty], true)
            || preg_match('/^([a-z][a-z0-9_]{0,63}|[A-Z][A-Z0-9_]{0,63})$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Typed failure required');
        }
        parent::__construct($errorCode);
    }

    public static function requireWrite(bool $written, string $code = 'stale_write'): void
    {
        if (!$written) { throw new self(Outcome::Incompatible, $code); }
    }
}
