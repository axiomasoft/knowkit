<?php

declare(strict_types=1);

namespace KnowKit\Port;

use Closure;
use KnowKit\Domain as D;

interface DisclosureStore
{
    public function replay(D\Scope $scope, D\DisclosureRequest $request): ?D\DisclosureReplay;

    public function plan(D\Scope $scope, D\SelectionRecord $record, D\DisclosureRequest $request): D\DisclosurePlan;

    /**
     * SQL-only closure runs under ordered shared guards with authoritative DB time,
     * returning the checked receipt deadline alongside the fully assembled buffer.
     * Return the response only after confirmed COMMIT; ambiguous COMMIT is unknown without bytes.
     * @param Closure(D\DisclosurePlan, D\UtcInstant): D\PreparedDisclosure $assemble
     */
    public function commit(
        D\Scope $scope,
        D\SelectionRecord $record,
        D\DisclosureRequest $request,
        D\DisclosurePlan $plan,
        Closure $assemble,
    ): D\DisclosureOutcome;
}
