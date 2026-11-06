<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{DeliveryLease};

interface OutboxDelivery
{
    /** @return list<DeliveryLease> Bounded tenant-bound batch, external send occurs AFTER lease commit. */
    public function leaseDelivery(int $limit): array;
    /** Conditional delivery fence; false cannot become successful acknowledgement. */
    public function ack(DeliveryLease $lease): bool;
}
