<?php
declare(strict_types=1);

namespace KnowKit\Application\Access;

use KnowKit\Domain\{DecisionReceipt, DisclosureDeadline, DomainFailure, Operation, Outcome, ResourceRef, Sha256, UtcInstant};
use KnowKit\Domain\Access\OwnerFreshness;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Domain\Layers\{DisclosureMode, ResolutionTier};

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

    public function disclosureDeadline(): DisclosureDeadline
    {
        $deadline = null;
        $window = $this->freshness === null ? 5 : $this->freshness->maxWindowSeconds;
        foreach ($this->receipts as [$resource, $receipt]) {
            $wire = $receipt->toWire();
            $checked = new UtcInstant($wire->get('checkedAt'));
            $windowEnd = $checked->plusSeconds($window);
            $expires = new UtcInstant($wire->get('expiresAt'));
            $candidate = $windowEnd->compare($expires) < 0 ? $windowEnd : $expires;
            if ($deadline === null || $candidate->compare($deadline) < 0) {
                $deadline = $candidate;
            }
        }

        return new DisclosureDeadline($deadline ?? throw new \LogicException('Nonempty receipt set required'), $this->freshness !== null);
    }

    public function maxDisclosureMode(): DisclosureMode
    {
        $effective = DisclosureMode::VerbatimAllowed;
        $rank = static fn (DisclosureMode $mode): int => match ($mode) {
            DisclosureMode::VerbatimAllowed => 4,
            DisclosureMode::DerivedOnly => 3,
            DisclosureMode::SummaryOnly => 2,
            DisclosureMode::MetadataOnly => 1,
            DisclosureMode::IndexOnly => 0,
        };
        foreach ($this->receipts as [, $receipt]) {
            $mode = $receipt->maxDisclosureMode();
            if ($rank($mode) < $rank($effective)) {
                $effective = $mode;
            }
        }

        return $effective;
    }

    public function requireTier(ResolutionTier $tier): void
    {
        if (!$this->maxDisclosureMode()->allowsTier($tier)) {
            throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
        }
    }

    public function requireDirectRead(): void
    {
        if (!$this->maxDisclosureMode()->allowsDirectRead()) {
            throw new DomainFailure(Outcome::Denied, 'verbatim_disclosure_forbidden');
        }
    }
}
