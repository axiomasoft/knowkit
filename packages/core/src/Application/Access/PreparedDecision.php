<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{DecisionReceipt, DomainFailure, Operation, Outcome, ResourceRef, Sha256, UtcInstant};
use KnowKit\Domain\Access\OwnerFreshness;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};

/** One bounded boundary attempt, checked with authoritative epochs and DB time under shared guards. */
final class PreparedDecision
{
    private bool $consumed = false;

    /** @param list<array{ResourceRef, DecisionReceipt}> $receipts Nonempty, checked at construction. */
    public function __construct(private readonly AccessContext $context, private readonly Operation $operation,
        private readonly array $receipts, private readonly ?OwnerFreshness $freshness)
    {
        if ($receipts === []) { throw new \InvalidArgumentException('Nonempty owner receipt set required'); }
    }

    /** No owner/host callback here. Caller holds guards and supplies its already authenticated context. */
    public function requireAllow(AccessContext $current, JsonObject $binding, UtcInstant $databaseNow): void
    {
        if ($this->consumed) { throw new DomainFailure(Outcome::Denied, 'decision_consumed'); }
        $this->consumed = true;
        $this->context->policy->assertSame($current->policy);
        $digest = new Sha256(HashDomain::PolicyBinding->digest($binding));
        foreach ($this->receipts as [$resource, $receipt]) {
            $this->freshness?->requireFresh($receipt, $databaseNow);
            $receipt->requireAllow($current->scope(), $resource, $this->operation, $databaseNow, $digest);
        }
    }
}
