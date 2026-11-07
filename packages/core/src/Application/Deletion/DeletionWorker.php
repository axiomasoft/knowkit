<?php
declare(strict_types=1);
namespace KnowKit\Application\Deletion;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, JsonObject};
use KnowKit\Port\{Clock, DeletionStore, DeletionTransport};

/** Privately adapted AgenTools DeletionWorker; canonical outcomes and exact-object proof are KnowKit-specific. */
final readonly class DeletionWorker
{
    public function __construct(private DeletionStore $store, private DeletionTransport $transport,
        private Clock $clock, private ContractValidator $validator) {}

    public function attempt(D\TenantId $tenant, D\DeletionTaskId $task, D\ResourceId $resource, D\AttemptId $attempt): D\DeletionReceipt
    {
        $start = $this->store->beginAttempt($tenant, $task, $resource, $attempt);
        if ($start->replay !== null) { return $start->replay; }
        $error = null;
        try {
            $proof = $this->transport->provesExactVersion($start->target);
            $receipt = $this->transport->delete($start->target, $attempt);
            $w = $receipt->toWire();
            if ($w->get('attemptId') !== $attempt->value || $w->get('taskId') !== $task->value
                || $w->get('resourceId') !== $resource->value || $w->get('inFlight') || $w->get('result') === null) {
                $error = 'DELETION_RECEIPT_MISMATCH';
            } elseif (in_array($w->get('result'), ['deleted', 'absent'], true)
                && (!$proof || $w->get('providerReceipt') === null)) {
                $error = 'DELETION_UNPROVEN';
            }
        } catch (\Throwable) { $error = 'DELETION_TRANSPORT_EXCEPTION'; }
        if ($error !== null) {
            $receipt = new D\DeletionReceipt(new JsonObject(['attemptId'=>$attempt->value, 'taskId'=>$task->value,
                'resourceId'=>$resource->value, 'inFlight'=>false, 'result'=>'unknown', 'providerReceipt'=>null,
                'knownExpiry'=>null, 'observedAt'=>$this->clock->now()->value]), $this->validator);
        }
        return $this->store->finishAttempt($start->target, $attempt, $receipt, $error);
    }
}
