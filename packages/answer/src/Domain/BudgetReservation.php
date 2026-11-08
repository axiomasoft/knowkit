<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

final readonly class BudgetReservation
{
    public function __construct(
        public InvocationRef $invocationRef,
        public string $ref,
        public ReservationStatus $status,
    ) {
        Guard::reference($ref);
    }
}
