<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Scope, QueryGrant, ResourceRef, Operation, DecisionReceipt};

interface AccessPolicy
{
    /** Current grant intersects trusted eligibility and typed filters BEFORE backend top-k. */
    public function authorizeQuery(Scope $scope): QueryGrant;
    public function decide(Scope $scope, ResourceRef $resource, Operation $operation): DecisionReceipt;
}
