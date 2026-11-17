<?php
declare(strict_types=1);

namespace KnowKit\Application\Sources;

use KnowKit\Domain as D;
use KnowKit\Domain\Sources\PreviousInventory;
use KnowKit\Domain\Sources\ReconciliationOutcome;
use KnowKit\Port\InventoryStore;

final readonly class InventoryReconciler
{
    public function __construct(
        private InventoryStore $store,
    ) {}

    public function reconcile(
        D\Scope $scope,
        PreviousInventory $previous,
        D\CrawlSnapshot $snapshot,
        D\MutationContext $context,
    ): ReconciliationOutcome {
        $previous->assertMatches($scope, $snapshot);
        $receipt = $snapshot->receipt;
        if ($receipt === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'unsupported_completeness_proof');
        }
        $receipt->assertMatches($scope, $snapshot);
        $outcome = $this->compare($previous, $snapshot, $receipt);

        return $this->store->synchronize($scope, $previous, $outcome, $context, function () use ($scope, $previous, $outcome, $context): ReconciliationOutcome {
            foreach ($outcome->removed as $removed) {
                $this->store->deny($scope, $previous, $removed, $context);
            }
            $this->store->publish($scope, $previous, $outcome, $context);

            return $outcome;
        });
    }

    private function compare(
        PreviousInventory $previous,
        D\CrawlSnapshot $snapshot,
        D\Sources\InventoryReceipt $receipt,
    ): ReconciliationOutcome {
        $old = PreviousInventory::byOrigin($previous->members);
        $present = PreviousInventory::byOrigin(array_values(array_filter(
            $snapshot->items, static fn (D\CrawlItem $item): bool => $item->status === 'verified',
        )));
        $this->assertRenames($receipt, $old, $present);
        $absent = array_diff_key($old, $present);
        $members = $snapshot->complete ? $present : array_replace($old, $present);
        ksort($members, SORT_STRING);

        return new ReconciliationOutcome(
            $snapshot, array_values($members), $snapshot->complete ? array_values($absent) : [],
            $snapshot->complete ? [] : array_map('strval', array_keys($absent)),
            $snapshot->complete ? [] : array_values(array_unique([
                'partial_inventory', ...$snapshot->failures, ...$receipt->reasons,
            ])),
        );
    }

    /**
     * @param array<string,D\CrawlItem> $old
     * @param array<string,D\CrawlItem> $present
     */
    private function assertRenames(D\Sources\InventoryReceipt $receipt, array $old, array $present): void
    {
        $renamed = [];
        $targets = [];
        foreach ($receipt->renames as $rename) {
            if (isset($renamed[$rename->oldOriginKey]) || isset($targets[$rename->newOriginKey])
                || ($old[$rename->oldOriginKey]->logicalPath ?? null) !== $rename->oldPath
                || ($present[$rename->newOriginKey]->logicalPath ?? null) !== $rename->newPath
                || ($rename->oldOriginKey !== $rename->newOriginKey && isset($old[$rename->newOriginKey]))
                || ($rename->oldOriginKey !== $rename->newOriginKey && isset($present[$rename->oldOriginKey]))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_rename');
            }
            $renamed[$rename->oldOriginKey] = true;
            $targets[$rename->newOriginKey] = true;
        }
        foreach ($present as $key => $item) {
            if (isset($old[$key]) && $old[$key]->logicalPath !== $item->logicalPath && !isset($renamed[$key])) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'explicit_rename_required');
            }
        }
    }
}
