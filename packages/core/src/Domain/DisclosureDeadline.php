<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Earliest expiry of already checked receipts, including the admitted external-owner window. */
final readonly class DisclosureDeadline
{
    public function __construct(
        public UtcInstant $expiresAt,
        public bool $externalOwner,
    ) {}

    public function requireFresh(UtcInstant $now): void
    {
        if ($this->expiresAt->compare($now) <= 0) {
            throw new DomainFailure($this->externalOwner ? Outcome::Unknown : Outcome::Denied,
                $this->externalOwner ? 'owner_freshness_unknown' : 'POLICY_CHANGED');
        }
    }
}
