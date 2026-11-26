<?php
declare(strict_types=1);
namespace KnowKit\Application\Events;

use KnowKit\Domain\Contract\{ContractViolation,JsonObject};
use KnowKit\Port\{EventTransport,OutboxDispatch};
use KnowKit\Application\Observability\Telemetry;
use KnowKit\Domain\Observability as O;
use KnowKit\Domain\{DomainFailure, Outcome};

final readonly class Dispatcher
{
    public function __construct(
        private OutboxDispatch $outbox,
        private EventTransport $transport,
        private ?Telemetry $telemetry = null,
    ) {}
    public function dispatch(int $limit = 64): JsonObject
    {
        $counts = ['delivered'=>0,'retried'=>0,'quarantined'=>0,'fenceLost'=>0];
        foreach ($this->outbox->leaseDelivery($limit) as $lease) {
            // Read/validation errors are distinct from transport failures and must not leak their data.
            try { $event = $this->outbox->event($lease); }
            catch (ContractViolation) {
                ++$counts[$this->outbox->quarantine($lease) ? 'quarantined' : 'fenceLost']; continue;
            } catch (DomainFailure) {
                // A lost fence is not poison and must not abandon the rest of the leased batch.
                ++$counts['fenceLost']; continue;
            }
            try { $this->transport->send($event); }
            catch (\Throwable) {
                ++$counts[$this->outbox->retry($lease, 5) ? 'retried' : 'fenceLost']; continue;
            }
            ++$counts[$this->outbox->ack($lease) ? 'delivered' : 'fenceLost'];
        }
        $this->observe($counts);

        return new JsonObject($counts);
    }

    /** @param array<string, int> $counts */
    private function observe(array $counts): void
    {
        if ($this->telemetry === null) {
            return;
        }
        try {
            $status = $this->outbox->status();
            $lag = $status->get('oldestOutstandingSeconds');
            if (!is_string($lag) || preg_match('/^(0|[1-9][0-9]{0,8})$/D', $lag) !== 1) {
                throw new \UnexpectedValueException('Invalid outbox lag');
            }
            $this->telemetry->emit(
                event: O\Event::Outbox, stage: O\Stage::Dispatch,
                outcome: $counts['retried'] + $counts['quarantined'] + $counts['fenceLost'] > 0 ? Outcome::Unavailable : Outcome::Success,
                measurements: new O\Measurements(outboxLagSeconds: (int) $lag),
            );
        } catch (\Throwable) {
            $this->telemetry->emit(
                event: O\Event::Outbox, stage: O\Stage::Dispatch,
                outcome: Outcome::Unavailable, reason: O\Reason::DependencyUnavailable,
            );
        }
    }
}
