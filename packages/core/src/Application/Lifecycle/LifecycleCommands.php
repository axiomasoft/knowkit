<?php
declare(strict_types=1);
namespace KnowKit\Application\Lifecycle;

use KnowKit\Application\Catalog\MutationAuthorizer;
use KnowKit\Application\Lifecycle;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\{CatalogRepository, CatalogTransaction, LifecycleCommandStore};

/** Owner I/O precedes BEGIN; callbacks inside a transaction perform SQL only. */
final readonly class LifecycleCommands implements Lifecycle
{
    /** @param \Closure(D\Scope,D\SourceVersion,D\MutationContext):D\SourceVersion $acceptVersion */
    public function __construct(
        private LifecycleCommandStore $store,
        private CatalogRepository $catalog,
        private CatalogTransaction $transaction,
        private MutationAuthorizer $authority,
        private \Closure $acceptVersion,
        private LifecycleGuarantee $guarantee = new LifecycleGuarantee(),
    ) {}

    public function guarantee(): LifecycleGuarantee { return $this->guarantee; }

    public function correct(D\Scope $scope, D\Correction $command, D\IdempotencyKey $key): void
    {
        $scope->assertTenant($command->oldVersion->tenantId);
        $source = $command->oldVersion->sourceId;
        $fields = new JsonObject(['oldVersion'=>$command->oldVersion->toWire(), 'replacement'=>$command->replacement?->toWire()]);
        $this->mutate($scope, $source, D\Operation::LifecycleCorrect, $key, $fields, $command->expectedLifecycleEpoch,
            function () use ($scope, $command): JsonObject {
                self::requireCurrent($this->store->correctVersion($scope, $command));
                return new JsonObject(['lifecycleEpoch'=>$command->expectedLifecycleEpoch->next()->value]);
            });
    }

    /** $stageReplacement may assemble precomputed SQL metadata; it must never do external I/O. */
    public function acceptAndCorrect(D\Scope $scope, D\SourceVersion $replacement, D\Correction $correction,
        D\IdempotencyKey $key, ?\Closure $stageReplacement = null): void
    {
        $scope->assertTenant($replacement->ref->tenantId);
        $scope->assertTenant($correction->oldVersion->tenantId);
        if ($correction->replacement?->toWire() != $replacement->ref->toWire()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $fields = new JsonObject(['mode'=>'accept_and_correct', 'oldVersion'=>$correction->oldVersion->toWire(),
            'replacement'=>$replacement->ref->toWire(), 'mime'=>$replacement->mime, 'rightsRef'=>$replacement->rightsRef,
            'blob'=>new JsonObject(['tenantId'=>$replacement->blob->tenantId->value, 'sourceId'=>$replacement->blob->sourceId->value,
                'digest'=>$replacement->blob->digest->value, 'byteLength'=>$replacement->blob->byteLength->value,
                'storageId'=>$replacement->blob->storageId, 'objectVersion'=>$replacement->blob->objectVersion,
                'ownerKind'=>$replacement->blob->ownerKind]), 'createdAt'=>$replacement->createdAt->value]);
        $this->mutate($scope, $correction->oldVersion->sourceId, D\Operation::LifecycleCorrect, $key, $fields,
            $correction->expectedLifecycleEpoch, function () use ($scope, $replacement, $correction, $stageReplacement): JsonObject {
                $source = $this->store->getSourceState($scope, $replacement->ref->sourceId);
                if ($source === null || $source->lifecycleState !== D\SourceLifecycleState::Active
                    || $this->store->getTombstone($scope, $source->sourceId) !== null
                    || $source->lifecycleEpoch->value !== $correction->expectedLifecycleEpoch->value) {
                    throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED');
                }
                $this->catalog->insertSourceVersion($scope->tenantId, $replacement);
                if ($stageReplacement !== null) { $stageReplacement($scope, $replacement); }
                self::requireCurrent($this->store->correctVersion($scope, $correction));
                return new JsonObject(['lifecycleEpoch'=>$correction->expectedLifecycleEpoch->next()->value]);
            });
    }

    public function publishOrdinaryVersion(D\Scope $scope, D\SourceVersion $version, D\MutationContext $context): D\SourceVersion
    {
        $scope->assertTenant($version->ref->tenantId);
        $this->authority->prepare($scope, new D\ResourceRef($scope->tenantId, 'source', $version->ref->sourceId), D\Operation::SourceVersionAdd);
        return $this->withSourceLocks($scope, $version->ref->sourceId, fn () => ($this->acceptVersion)($scope, $version, $context));
    }

    /** Activates an already sealed replacement without invalidating permitted ordinary history. */
    public function publishSnapshot(D\Scope $scope, D\SourceVersionRef $version, D\SnapshotId $snapshot,
        D\Decimal $expectedLifecycleEpoch, D\Decimal $expectedPointerVersion, D\IdempotencyKey $key): void
    {
        $scope->assertTenant($version->tenantId);
        $fields = new JsonObject(['version'=>$version->toWire(), 'snapshotId'=>$snapshot->value,
            'expectedPointerVersion'=>$expectedPointerVersion->value]);
        $this->mutate($scope, $version->sourceId, D\Operation::Publish, $key, $fields, $expectedLifecycleEpoch,
            function () use ($scope, $version, $snapshot, $expectedLifecycleEpoch, $expectedPointerVersion): JsonObject {
                $this->store->activateSnapshot($scope, $version, $snapshot, $expectedLifecycleEpoch, $expectedPointerVersion);
                return new JsonObject(['pointerVersion'=>$expectedPointerVersion->next()->value]);
            });
    }

    public function revoke(D\Scope $scope, D\Revocation $command, D\IdempotencyKey $key): void
    {
        $this->mutate($scope, $command->sourceId, D\Operation::LifecycleRevoke, $key,
            new JsonObject(['sourceId'=>$command->sourceId->value, 'reason'=>$command->reason]), $command->expectedLifecycleEpoch,
            function () use ($scope, $command): JsonObject {
                self::requireCurrent($this->store->revokeIdentity($scope, $command));
                return new JsonObject(['lifecycleEpoch'=>$command->expectedLifecycleEpoch->next()->value]);
            });
    }

    /** A consent withdrawal is identity-wide; ACL-only receipt invalidation remains PolicyEpoch's job. */
    public function revokeAccess(D\Scope $scope, D\SourceId $source, D\Decimal $expectedPolicyEpoch,
        D\Decimal $expectedLifecycleEpoch, D\IdempotencyKey $key): D\Decimal
    {
        $response = $this->mutate($scope, $source, D\Operation::LifecycleRevoke, $key,
            new JsonObject(['sourceId'=>$source->value, 'reason'=>'consent_revoked', 'expectedPolicyEpoch'=>$expectedPolicyEpoch->value]),
            $expectedLifecycleEpoch, function () use ($scope, $source, $expectedPolicyEpoch, $expectedLifecycleEpoch): JsonObject {
                $state = $this->store->getSourceState($scope, $source);
                if (($state !== null && $state->sourcePolicyEpoch->value !== $expectedPolicyEpoch->value)
                    || ($state === null && $expectedPolicyEpoch->value !== '0')) {
                    throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED');
                }
                self::requireCurrent($this->store->revokeIdentity($scope,
                    new D\Revocation($source, $expectedLifecycleEpoch, 'consent_revoked')));
                return new JsonObject(['sourcePolicyEpoch'=>$expectedPolicyEpoch->next()->value]);
            });
        return new D\Decimal($response->get('sourcePolicyEpoch'));
    }

    public function placeLegalHold(D\Scope $scope, D\LegalHold $command, D\IdempotencyKey $key): void
    {
        $this->mutate($scope, $command->sourceId, D\Operation::LegalHoldSet, $key,
            new JsonObject(['sourceId'=>$command->sourceId->value, 'enabled'=>$command->enabled]), $command->expectedLifecycleEpoch,
            function () use ($scope, $command): JsonObject {
                self::requireCurrent($this->store->setRetentionHold($scope, $command));
                return new JsonObject(['lifecycleEpoch'=>$command->expectedLifecycleEpoch->next()->value]);
            });
    }

    public function requestPurge(D\Scope $scope, D\PurgeRequest $command, D\IdempotencyKey $key): D\DeletionTaskId
    {
        $response = $this->mutate($scope, $command->sourceId, D\Operation::LifecycleDelete, $key,
            new JsonObject(['sourceId'=>$command->sourceId->value, 'kind'=>'source']), $command->expectedLifecycleEpoch,
            function () use ($scope, $command): JsonObject {
                $task = $this->store->purgeSource($scope, $command);
                if ($task === null) { throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED'); }
                return new JsonObject(['taskId'=>$task->value]);
            });
        return new D\DeletionTaskId($response->get('taskId'));
    }

    private static function requireCurrent(bool $written): void
    {
        if (!$written) { throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED'); }
    }

    /** @param \Closure():JsonObject $effect */
    private function mutate(D\Scope $scope, D\SourceId $source, D\Operation $operation, D\IdempotencyKey $key,
        JsonObject $fields, D\Decimal $epoch, \Closure $effect): JsonObject
    {
        $this->authority->prepare($scope, new D\ResourceRef($scope->tenantId, 'source', $source), $operation);
        return $this->withSourceLocks($scope, $source, fn () => $this->store->executeIdempotent(
            $scope, $source, $operation, $key, $fields->with('tenantId', $scope->tenantId->value), $epoch, $effect));
    }

    /** Tenant UPDATE stabilizes the complete membership/source set before the sorted subordinate locks. */
    private function withSourceLocks(D\Scope $scope, D\SourceId $sourceId, \Closure $callback): mixed
    {
        return $this->transaction->run(function () use ($scope, $sourceId, $callback): mixed {
            $this->transaction->lockTenant($scope->tenantId, D\LockMode::Update);
            $kbs = [];
            if ($this->catalog->getKb($scope->tenantId, $scope->kbId) !== null) { $kbs[$scope->kbId->value] = $scope->kbId; }
            foreach ($this->catalog->listMemberships($scope->tenantId, $sourceId) as $membership) {
                $kbs[$membership->kbId->value] = $membership->kbId;
            }
            ksort($kbs, SORT_STRING);
            foreach ($kbs as $kbId) { $this->transaction->lockKb($kbId, D\LockMode::Update); }
            if ($this->store->getSourceState($scope, $sourceId) !== null) {
                $this->transaction->lockSources([$sourceId], D\LockMode::Update);
            }
            $this->store->lockWork($scope, $sourceId);
            return $callback();
        });
    }
}
