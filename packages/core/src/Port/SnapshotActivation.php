<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain\{ActivationRequest, PublishReceipt};

interface SnapshotActivation
{
    /** Tenant/key-bound metadata only. Different command fingerprints conflict. */
    public function replay(ActivationRequest $request): ?PublishReceipt;
    /** Recheck replay and fresh prepared policy/lifecycle/readiness under ordered guards, then commit atomically. */
    public function activate(ActivationRequest $request): PublishReceipt;
}
