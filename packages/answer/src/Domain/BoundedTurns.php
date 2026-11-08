<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;

final readonly class BoundedTurns
{
    /** @param list<PriorTurn> $turns */
    public function __construct(
        public array $turns = [],
        public ?HistoryReadAttestation $attestation = null,
    ) {
        Guard::objects($turns, PriorTurn::class, 12);
        if ($this->byteLength() > 16384) {
            throw new \InvalidArgumentException('Prior turns exceed UTF-8 byte ceiling');
        }
    }

    public function byteLength(): int
    {
        return array_sum(array_map(static fn (PriorTurn $turn): int => strlen($turn->text), $this->turns));
    }

    public function digest(): Sha256
    {
        $ordered = array_map(static fn (PriorTurn $turn) => $turn->toWire(), $this->turns);

        return new Sha256(hash('sha256', Jcs::canonicalize($ordered)));
    }

    public function requireCurrentAttestation(Scope $scope, DateTimeImmutable $now): void
    {
        if ($this->turns === []) {
            return;
        }
        if ($this->attestation === null) {
            throw new DomainFailure(Outcome::Denied, 'history_attestation_required');
        }
        $this->attestation->requireCurrent($scope, $this->digest(), $now);
    }

    public function __debugInfo(): array
    {
        return ['count' => count($this->turns), 'bytes' => $this->byteLength()];
    }
}
