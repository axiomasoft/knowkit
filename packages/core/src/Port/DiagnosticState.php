<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Observability\RuntimeState;
use KnowKit\Domain\Scope;

/** Host reads current tenant/KB-scoped metadata. No model calls, body loads or adapter construction. */
interface DiagnosticState
{
    public function current(Scope $scope): RuntimeState;
}
