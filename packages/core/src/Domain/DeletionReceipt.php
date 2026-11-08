<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class DeletionReceipt extends SchemaValue
{
    protected const SCHEMA = 'deletion-attempt';

    public function operationalOutcome(): Outcome
    {
        return match ($this->toWire()->get('result')) {
            'deleted', 'absent' => Outcome::Success,
            'unsupported' => Outcome::Incompatible,
            default => Outcome::Unknown,
        };
    }
}
