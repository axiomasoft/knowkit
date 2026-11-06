<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Scope, EgressRequest, DecisionReceipt};

interface EgressPolicy
{
    /** Recheck immediately before EACH provider transmission; request covers the complete manifest. */
    public function mayEgress(Scope $scope, EgressRequest $request): DecisionReceipt;
}
