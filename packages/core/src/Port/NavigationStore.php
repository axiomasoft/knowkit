<?php

declare(strict_types=1);

namespace KnowKit\Port;

use Closure;
use KnowKit\Domain as D;
use KnowKit\Domain\Navigation as N;

interface NavigationStore
{
    public function plan(D\Scope $scope, D\DocumentRevisionRef $revision): N\NavigationPlan;

    /**
     * Final metadata gate: exact identity, epochs, lifecycle, ancestors and object states under guards.
     * @param list<N\SourceUnitRef> $units
     * @param Closure(N\NavigationPlan,D\UtcInstant):D\DisclosureDeadline $authorize Local checks only.
     */
    public function check(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan, array $units, Closure $authorize): void;

    /**
     * Atomically persist reference-only Usage + outbox and return only after confirmed COMMIT.
     * @param list<N\Unit> $units
     * @param Closure(N\NavigationPlan,D\UtcInstant):D\DisclosureDeadline $authorize Local checks only.
     */
    public function disclose(D\Scope $scope, D\DocumentRevisionRef $revision, N\NavigationPlan $plan,
        array $units, D\Ulid $attempt, Closure $authorize): N\UnitDisclosure|D\DisclosureReplay;
}
