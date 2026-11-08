<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Decimal;

final readonly class LedgerClaim
{
    public function __construct(
        public InvocationRef $invocationRef,
        public Decimal $rowVersion,
        public string $leaseOwner,
        public DateTimeImmutable $leaseUntil,
        public Decimal $fencingEpoch,
    ) {
        Guard::reference($leaseOwner);
    }
}
