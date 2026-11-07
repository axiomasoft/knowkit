<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain\{DeliveryLease,DomainEvent};
use KnowKit\Domain\Contract\JsonObject;

interface OutboxDispatch extends OutboxDelivery
{
    public function event(DeliveryLease $lease): DomainEvent;
    public function retry(DeliveryLease $lease, int $delaySeconds): bool;
    public function quarantine(DeliveryLease $lease): bool;
    /** Tenant-scoped counts, oldest outstanding age and transport errors; no raw diagnostics. */
    public function status(): JsonObject;
}
