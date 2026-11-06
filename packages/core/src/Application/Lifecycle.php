<?php
declare(strict_types=1);

namespace KnowKit\Application;

use KnowKit\Domain\{Scope, Correction, IdempotencyKey, Revocation, LegalHold, PurgeRequest, DeletionTaskId};

/** Public use-case contract; delivery adapters and persistence implementations belong to their owning items. */
interface Lifecycle
{
    public function correct(Scope $scope, Correction $command, IdempotencyKey $key): void;
    public function revoke(Scope $scope, Revocation $command, IdempotencyKey $key): void;
    public function placeLegalHold(Scope $scope, LegalHold $command, IdempotencyKey $key): void;
    public function requestPurge(Scope $scope, PurgeRequest $command, IdempotencyKey $key): DeletionTaskId;
}
