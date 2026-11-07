<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Sources;

use Closure;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Sources\PreviousInventory;
use KnowKit\Domain\Sources\ReconciliationOutcome;
use KnowKit\Port\InventoryStore;

/** Single-process SDK double, not durable storage or a replacement for the catalog. */
final class RecordedInventoryStore implements InventoryStore
{
    /** @var array<string,PreviousInventory> */
    private array $partitions = [];
    /** @var array<string,true> */
    private array $denied = [];
    /** @var array<string,array{digest:string,outcome:ReconciliationOutcome}> */
    private array $receipts = [];
    /** @var array<string,array<string,D\CrawlItem>> */
    private array $snapshots = [];
    private bool $guarded = false;
    public int $publications = 0;

    /** @param Closure(D\Scope,D\Operation):void $authorize @param Closure():void $beforePublish */
    public function __construct(
        private readonly Closure $authorize,
        private readonly Closure $beforePublish,
    ) {}

    public function previous(D\Scope $scope, D\AdapterId $connector, string $namespace): PreviousInventory
    {
        ($this->authorize)($scope, D\Operation::Ingest);
        $key = self::partitionKey($scope, $connector, $namespace);
        $old = $this->partitions[$key] ?? null;
        if ($old === null) {
            return new PreviousInventory($scope, $connector, $namespace, new D\Decimal('0'), []);
        }

        return new PreviousInventory($scope, $connector, $namespace, $old->revision, $old->members);
    }

    public function synchronize(D\Scope $scope, PreviousInventory $previous, ReconciliationOutcome $candidate, D\MutationContext $context, Closure $effect): ReconciliationOutcome
    {
        ($this->authorize)($scope, D\Operation::Ingest);
        $previous->assertMatches($scope, $candidate->inventory);
        $key = self::partitionKey($scope, $previous->connectorId, $previous->sourceNamespace);
        $replayKey = Jcs::canonicalize([self::kbKey($scope), $context->idempotencyKey()->value]);
        $digest = Jcs::canonicalize([$candidate->inventory->inventoryDigest->value, $previous->revision->value, $context->expectedRevision()?->value, $context->expectedEpoch()?->value]);
        if (isset($this->receipts[$replayKey])) {
            if ($this->receipts[$replayKey]['digest'] !== $digest) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'idempotency_conflict');
            }

            return $this->receipts[$replayKey]['outcome'];
        }
        $this->assertCurrent($scope, $previous, $context, $key);
        $this->guarded = true;
        try {
            $outcome = $effect();
            $this->receipts[$replayKey] = ['digest' => $digest, 'outcome' => $outcome];

            return $outcome;
        } finally {
            $this->guarded = false;
        }
    }

    private function assertCurrent(D\Scope $scope, PreviousInventory $previous, D\MutationContext $context, string $key): void
    {
        $current = $this->partitions[$key] ?? new PreviousInventory(
            $scope, $previous->connectorId, $previous->sourceNamespace, new D\Decimal('0'), [],
        );
        if ($this->guarded || $current->revision->value !== $previous->revision->value
            || $current->members != $previous->members) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stale_write');
        }
        $context->assertExpected($previous->revision, $scope->policyEpoch);
    }

    public function deny(D\Scope $scope, PreviousInventory $previous, D\CrawlItem $removed, D\MutationContext $context): void
    {
        $this->requireGuard();
        ($this->authorize)($scope, D\Operation::LifecycleDelete);
        $this->denied[self::memberKey($scope, $previous, $removed)] = true;
    }

    public function publish(D\Scope $scope, PreviousInventory $previous, ReconciliationOutcome $outcome, D\MutationContext $context): void
    {
        $this->requireGuard();
        ($this->beforePublish)();
        ($this->authorize)($scope, D\Operation::Publish);
        $snapshot = $this->mergeSnapshot($scope, $previous, $outcome);
        $key = self::partitionKey($scope, $previous->connectorId, $previous->sourceNamespace);
        $this->partitions[$key] = new PreviousInventory(
            $scope, $previous->connectorId, $previous->sourceNamespace, $previous->revision->next(), $outcome->members,
        );
        $this->snapshots[self::kbKey($scope)] = $snapshot;
        $this->publications++;
    }

    /** @return array<string,D\CrawlItem> */
    private function mergeSnapshot(D\Scope $scope, PreviousInventory $previous, ReconciliationOutcome $outcome): array
    {
        $snapshot = $this->snapshots[self::kbKey($scope)] ?? [];
        foreach ($previous->members as $item) {
            unset($snapshot[self::memberKey($scope, $previous, $item)]);
        }
        foreach ($outcome->members as $item) {
            $key = self::memberKey($scope, $previous, $item);
            if (isset($this->denied[$key])) {
                throw new D\DomainFailure(D\Outcome::Denied, 'source_revoked');
            }
            $snapshot[$key] = $item;
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /** Metadata only; check current authorization and deny markers even on an old snapshot. @return list<D\CrawlItem> */
    public function readable(D\Scope $scope): array
    {
        ($this->authorize)($scope, D\Operation::Disclose);
        $snapshot = $this->snapshots[self::kbKey($scope)] ?? [];

        return array_values(array_diff_key($snapshot, $this->denied));
    }

    /** SDK publication diagnostic, distinct from lifecycle-filtered readability. */
    public function activeMemberCount(D\Scope $scope): int
    {
        ($this->authorize)($scope, D\Operation::Diagnostics);

        return count($this->snapshots[self::kbKey($scope)] ?? []);
    }

    private function requireGuard(): void
    {
        if (!$this->guarded) {
            throw new D\DomainFailure(D\Outcome::Denied, 'inventory_guard_required');
        }
    }

    private static function kbKey(D\Scope $scope): string
    {
        return Jcs::canonicalize([$scope->tenantId->value, $scope->kbId->value]);
    }

    private static function partitionKey(D\Scope $scope, D\AdapterId $connector, string $namespace): string
    {
        return Jcs::canonicalize([self::kbKey($scope), $connector->value, $namespace]);
    }

    private static function memberKey(D\Scope $scope, PreviousInventory $previous, D\CrawlItem $item): string
    {
        return Jcs::canonicalize([self::partitionKey($scope, $previous->connectorId, $previous->sourceNamespace), $item->originKey]);
    }
}
