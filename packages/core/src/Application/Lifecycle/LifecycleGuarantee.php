<?php
declare(strict_types=1);
namespace KnowKit\Application\Lifecycle;

use KnowKit\Domain\{DecisionReceipt, DomainFailure, Outcome, UtcInstant};
use KnowKit\Domain\Access\OwnerFreshness;
use KnowKit\Domain\Contract\JsonObject;

/**
 * Explicit boundary contract: KnowKit guarantees local transactional serialization
 * under ordered PostgreSQL row locks (TenantId -> KbId -> SourceId) and bounds any
 * pre-transaction external-owner decision to OwnerFreshness (1..5s) without claiming
 * instantaneous cross-system distributed atomicity.
 */
final readonly class LifecycleGuarantee
{
    public const ISOLATION_LEVEL = 'READ COMMITTED';
    public const LOCK_ORDER = ['TenantId', 'KbId', 'SourceId', 'GenerationId', 'JobId'];

    public function __construct(public ?OwnerFreshness $ownerFreshness = null) {}

    public function requireFreshReceipt(DecisionReceipt $receipt, UtcInstant $commitNow): void
    {
        if ($this->ownerFreshness === null) {
            throw new DomainFailure(Outcome::Unknown, 'owner_freshness_unknown');
        }
        $this->ownerFreshness->requireFresh($receipt, $commitNow);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'localSerialization' => 'postgres_row_locks_read_committed',
            'lockOrder' => self::LOCK_ORDER,
            'unseenSourceTombstoneGuard' => 'tenants_for_update',
            'ordinaryVersionInvalidatesPrevious' => false,
            'distributedInstantAtomicityClaimed' => false,
            'externalOwnerWindowBoundSeconds' => $this->ownerFreshness?->maxWindowSeconds,
            'externalOwnerContractRevision' => $this->ownerFreshness?->contractRevision,
        ]);
    }
}
