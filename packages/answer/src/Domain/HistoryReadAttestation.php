<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

/** Trusted host receipt; supplied history bytes alone cannot mint this authority. */
final readonly class HistoryReadAttestation
{
    public function __construct(
        public Scope $scope,
        public Sha256 $orderedTurnsDigest,
        public string $ref,
        public DateTimeImmutable $expiresAt,
    ) {
        Guard::reference($ref);
    }

    public function requireCurrent(Scope $scope, Sha256 $digest, DateTimeImmutable $now): void
    {
        Guard::sameScope($this->scope, $scope);
        if ($this->expiresAt <= $now || $this->orderedTurnsDigest != $digest) {
            throw new DomainFailure(Outcome::Denied, 'history_attestation_invalid');
        }
    }
}
