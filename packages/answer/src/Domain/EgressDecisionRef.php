<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use DateTimeImmutable;
use KnowKit\Domain\Contract\JsonObject;

/** Receipt of a check for the exact ModelCallIdentity bytes/destination. */
final readonly class EgressDecisionRef
{
    public function __construct(
        public string $ref,
        public string $policyVersion,
        public DateTimeImmutable $checkedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        Guard::reference($ref);
        Guard::reference($policyVersion);
        if ($expiresAt <= $checkedAt) {
            throw new \InvalidArgumentException('Egress receipt must have a live check interval');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'ref' => $this->ref,
            'policyVersion' => $this->policyVersion,
            'checkedAt' => $this->checkedAt->format('Y-m-d\\TH:i:s.uP'),
            'expiresAt' => $this->expiresAt->format('Y-m-d\\TH:i:s.uP'),
        ]);
    }
}
