<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\ModelBindingRef;
use KnowKit\Answer\Domain\ResolvedModelBinding;
use KnowKit\Domain\Scope;

/** Called only for first admission, after tenant/key ledger lookup. No paid probes. */
interface ModelBindingResolver
{
    public function resolve(Scope $scope, ModelBindingRef $ref): ResolvedModelBinding;
}
