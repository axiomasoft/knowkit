<?php
declare(strict_types=1);

namespace KnowKit\Domain\Access;

use KnowKit\Domain\{DecisionReceipt, DomainFailure, Outcome, UtcInstant};

/** Host-admitted remote check-to-commit bound, not proof of distributed atomicity. */
final readonly class OwnerFreshness
{
    public function __construct(public string $contractRevision, public int $maxWindowSeconds)
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}$/D', $contractRevision) !== 1
            || $maxWindowSeconds < 1 || $maxWindowSeconds > 5) {
            throw new \InvalidArgumentException('Explicit bounded owner freshness contract required');
        }
    }

    public function requireFresh(DecisionReceipt $receipt, UtcInstant $now): void
    {
        $wire = $receipt->toWire();
        $checked = new UtcInstant($wire->get('checkedAt'));
        if ($wire->get('ownerRevision') === null || $checked->compare($now) > 0
            || $checked->plusSeconds($this->maxWindowSeconds)->compare($now) <= 0
            || (new UtcInstant($wire->get('expiresAt')))->compare($now) <= 0) {
            throw new DomainFailure(Outcome::Unknown, 'owner_freshness_unknown');
        }
    }
}
