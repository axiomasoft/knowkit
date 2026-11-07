<?php
declare(strict_types=1);
namespace KnowKit\Application\Events;

use KnowKit\Domain\Contract\{ContractViolation,JsonObject};
use KnowKit\Port\{EventTransport,OutboxDispatch};

final readonly class Dispatcher
{
    public function __construct(private OutboxDispatch $outbox, private EventTransport $transport) {}
    public function dispatch(int $limit = 64): JsonObject
    {
        $counts = ['delivered'=>0,'retried'=>0,'quarantined'=>0,'fenceLost'=>0];
        foreach ($this->outbox->leaseDelivery($limit) as $lease) {
            // Read/validation errors are distinct from transport failures and must not leak their data.
            try { $event = $this->outbox->event($lease); }
            catch (ContractViolation) {
                ++$counts[$this->outbox->quarantine($lease) ? 'quarantined' : 'fenceLost']; continue;
            }
            try { $this->transport->send($event); }
            catch (\Throwable) {
                ++$counts[$this->outbox->retry($lease, 5) ? 'retried' : 'fenceLost']; continue;
            }
            ++$counts[$this->outbox->ack($lease) ? 'delivered' : 'fenceLost'];
        }
        return new JsonObject($counts);
    }
}
