<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Scope, SourceId, Source, SourceVersionId, SourceVersionState, Tombstone, Correction, Revocation, LegalHold};

interface LifecycleStore
{
    public function getSourceState(Scope $scope, SourceId $sourceId): ?Source;
    public function getVersionState(Scope $scope, SourceVersionId $sourceVersionId): ?SourceVersionState;
    public function getTombstone(Scope $scope, SourceId $sourceId): ?Tombstone;
    /** Caller holds ordered locks; false means stale epoch/conflict, not success. */
    public function correctVersion(Scope $scope, Correction $correction): bool;
    public function revokeIdentity(Scope $scope, Revocation $revocation): bool;
    public function setRetentionHold(Scope $scope, LegalHold $hold): bool;
}
