<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\BudgetReservation;
use KnowKit\Answer\Domain\Estimate;
use KnowKit\Answer\Domain\InvocationKey;
use KnowKit\Answer\Domain\ReleaseReason;
use KnowKit\Answer\Domain\UsageObservation;

/** Host-owned stable-key settlement, tenant-bound by Estimate.scope.
 * Unknown reserve prevents send; unknown usage retains funds.
 */
interface AnswerBudget
{
    public function reserve(InvocationKey $key, Estimate $estimate): BudgetReservation;

    public function commit(BudgetReservation $reservation, UsageObservation $usage): void;

    /** Only a confirmed unused reservation may be released. */
    public function release(BudgetReservation $reservation, ReleaseReason $reason): void;
}
