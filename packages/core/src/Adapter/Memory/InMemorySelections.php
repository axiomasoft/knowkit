<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Memory;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\SelectionRecord;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Port\SelectionStore;

/** Process-local reference implementation; discarded with its explicitly wired server instance. */
final class InMemorySelections implements SelectionStore
{
    /** @var array<string, SelectionRecord> */
    private array $records = [];
    /** @var array<string, true> */
    private array $ids = [];

    public function put(Scope $scope, Sha256 $tokenHash, SelectionRecord $record): void
    {
        $record->context->assertSame(new PolicyContext(
            $scope, $record->context->audience, $record->context->grants, $record->context->egress,
        ));
        $key = $scope->tenantId->value . ':' . $tokenHash->value;
        $id = $scope->tenantId->value . ':' . $record->selection->id()->value;
        if (isset($this->records[$key]) || isset($this->ids[$id])) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $this->records[$key] = $record;
        $this->ids[$id] = true;
    }

    public function get(Scope $scope, Sha256 $tokenHash): ?SelectionRecord
    {
        $record = $this->records[$scope->tenantId->value . ':' . $tokenHash->value] ?? null;
        if ($record === null || !$record->context->scope->hostScopeHash->equals($scope->hostScopeHash)) {
            return null;
        }
        $stored = $record->context->scope;
        if (!$scope->kbId->equals($stored->kbId) || !$scope->actorId->equals($stored->actorId)
            || $scope->purpose !== $stored->purpose) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        if ($scope->policyEpoch->value !== $stored->policyEpoch->value) {
            throw new DomainFailure(Outcome::Denied, 'POLICY_CHANGED');
        }

        return $record;
    }
}
