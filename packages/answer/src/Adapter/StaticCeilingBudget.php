<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\AnswerBudget;

/** Stateless configured ceilings only; this is not a monetary or durable reservation ledger. */
final readonly class StaticCeilingBudget implements AnswerBudget
{
    public function __construct(
        private int $inputBytes = 32768,
        private ?int $inputTokens = 8192,
        private int $outputTokens = 2048,
        private int $outputBytes = 65536,
    ) {
        if ($inputBytes < 1 || ($inputTokens !== null && $inputTokens < 1) || $outputTokens < 1 || $outputBytes < 1) {
            throw new \InvalidArgumentException('Positive ceilings required');
        }
    }

    public function reserve(A\InvocationKey $key, A\Estimate $estimate): A\BudgetReservation
    {
        $allowed = $estimate->inputBytes <= $this->inputBytes
            && $estimate->maxOutputTokens <= $this->outputTokens && $estimate->maxOutputBytes <= $this->outputBytes
            && ($this->inputTokens === null || ($estimate->inputTokens !== null && $estimate->inputTokens <= $this->inputTokens));
        $ref = hash('sha256', $estimate->scope->tenantId->value . "\0" . $key->value);

        return new A\BudgetReservation(new A\InvocationRef($estimate->scope->tenantId, $key), 'ceiling:' . $ref,
            $allowed ? A\ReservationStatus::Confirmed : A\ReservationStatus::Denied);
    }

    public function commit(A\BudgetReservation $reservation, A\UsageObservation $usage): void
    {
        $this->requireConfirmed($reservation);
    }

    public function release(A\BudgetReservation $reservation, A\ReleaseReason $reason): void
    {
        $this->requireConfirmed($reservation);
    }

    private function requireConfirmed(A\BudgetReservation $reservation): void
    {
        if ($reservation->status !== A\ReservationStatus::Confirmed) {
            throw new \InvalidArgumentException('Only a confirmed reservation can be settled');
        }
    }
}
