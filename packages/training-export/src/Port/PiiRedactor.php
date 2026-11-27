<?php

declare(strict_types=1);

namespace KnowKit\TrainingExport\Port;

use KnowKit\Domain\ProfileId;

interface PiiRedactor
{
    public function id(): ProfileId;

    public function redact(string $utf8): string;

    /** Post-scan guard: returns true if any PII or secret pattern remains. */
    public function containsResidualPii(string $utf8): bool;
}
