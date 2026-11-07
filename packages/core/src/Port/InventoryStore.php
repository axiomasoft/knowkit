<?php
declare(strict_types=1);

namespace KnowKit\Port;

use Closure;
use KnowKit\Domain\CrawlItem;
use KnowKit\Domain\MutationContext;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sources\PreviousInventory;
use KnowKit\Domain\Sources\ReconciliationOutcome;

/** Host integration with existing authoritative lifecycle/publication, never a second catalog. */
interface InventoryStore
{
    /**
     * Hold authoritative tenant/KB/source guards; check current access, scope, previous
     * inventory CAS and MutationContext. Replay checks access and request identity again.
     * @param Closure():ReconciliationOutcome $effect
     */
    public function synchronize(Scope $scope, PreviousInventory $previous, ReconciliationOutcome $candidate, MutationContext $context, Closure $effect): ReconciliationOutcome;

    /** Persist lifecycle deny/delete intent for this exact identity before any rebuild. */
    public function deny(Scope $scope, PreviousInventory $previous, CrawlItem $removed, MutationContext $context): void;

    /**
     * Accept exact versions idempotently and replace only this namespace partition.
     * Merge other namespaces. Publish an empty snapshot when the last member goes;
     * never fall back to the old index. Recheck current access/epochs before activation.
     * Denies must survive projection failure; publication uses the existing sealed/CAS path.
     */
    public function publish(Scope $scope, PreviousInventory $previous, ReconciliationOutcome $outcome, MutationContext $context): void;
}
