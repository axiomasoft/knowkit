<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain as D;

/** Trusted worker port; every boundary rechecks tenant, source and immutable attempt coordinates. */
interface DeletionStore
{
    public function beginAttempt(D\TenantId $tenant, D\DeletionTaskId $task, D\ResourceId $resource, D\AttemptId $attempt): D\DeletionStart;
    public function finishAttempt(D\DeletionTarget $target, D\AttemptId $attempt, D\DeletionReceipt $receipt, ?string $errorCode = null): D\DeletionReceipt;
    public function aggregate(D\TenantId $tenant, D\DeletionTaskId $task): string;
}
