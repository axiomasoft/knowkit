<?php
declare(strict_types=1);

namespace KnowKit\Application;

use KnowKit\Domain\{Scope, RegisterSource, IdempotencyKey, SourceReceipt, SourceUpload, VersionReceipt};

/** Public use-case contract; delivery adapters and persistence implementations belong to their owning items. */
interface Ingestion
{
    public function registerSource(Scope $scope, RegisterSource $command, IdempotencyKey $key): SourceReceipt;
    public function addVersion(Scope $scope, SourceUpload $upload, IdempotencyKey $key): VersionReceipt;
}
