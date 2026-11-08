<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

/** Host-owned live cancellation signal; polling performs no model work. */
interface CancellationToken
{
    public function isCancelled(): bool;
}
