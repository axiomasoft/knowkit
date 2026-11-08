<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\AnswerBudget;

/** Host-owned stable-key settlement spy; deliberately no money-ledger claim. */
final class HostBudget implements AnswerBudget
{
    public bool $failCommit = false;
    public array $commits = [];
    public array $releases = [];

    public function reserve(A\InvocationKey $key, A\Estimate $estimate): A\BudgetReservation
    {
        return new A\BudgetReservation(new A\InvocationRef($estimate->scope->tenantId, $key),
            'budget:' . $key->value, A\ReservationStatus::Confirmed);
    }

    public function commit(A\BudgetReservation $reservation, A\UsageObservation $usage): void
    {
        $this->commits[] = [$reservation, $usage];
        if ($this->failCommit) {
            throw new \RuntimeException('Synthetic lost settlement ACK');
        }
    }

    public function release(A\BudgetReservation $reservation, A\ReleaseReason $reason): void
    {
        $this->releases[] = [$reservation, $reason];
    }
}
